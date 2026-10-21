# Probar el asistente del alojamiento de punta a punta

> 2026-10-20. Cómo probar en dev que un huésped le escribe al hotel por el chat de
> la web, Telegram o WhatsApp y le contesta el agente de Colmexa (`~/projects/messaging`)
> con los datos reales de HotelIgnite (`POST /mcp/accommodation`).
> Plan: [`accommodation-mcp-plan.md`](accommodation-mcp-plan.md). Lado Colmexa:
> `~/projects/messaging/CLAUDE.md`, sección *El agente en los tres canales*.

```
Huésped ─▶ widget / Telegram / WhatsApp ─▶ messaging (canal → ConversationRouter → agente)
                                               │ Bearer client_id.client_secret
                                               ▼
                               HotelIgnite  http://nginx/mcp/accommodation
                               get_accommodation_info · check_availability · create_inquiry
```

## Lo que ya quedó armado en dev

| Dónde | Qué | Valor |
|---|---|---|
| HotelIgnite | Alojamiento piloto | **Hotel Bonarda** (id 14), pasado de *Plan I* a **Starter** para que tenga `ai_assistant` |
| HotelIgnite | Credencial | `AccommodationApiKey` "colmexa-dev", `client_id = ak_live_zzw2hedl`. El secreto quedó en `/tmp/hi_key.txt` **adentro del contenedor `api`** (línea 1: client_id, línea 2: client_secret). Borralo cuando no lo necesites |
| messaging | Cliente | `hotel-bonarda` (tenant 4) |
| messaging | Bot | `asistente` (id 4), `type = agent`, con saludo y `business_name` en `settings` |
| messaging | Canal web | "Chat de la web" (id 11), clave `wk_live_e3456f34dff8c9356033692e43c01207`, origen permitido `http://localhost:5555`, pide email y teléfono |
| messaging | Conector | `hotel` → `http://nginx/mcp/accommodation`, vinculado al bot `asistente` |
| messaging | Docker | `messaging_app` y `messaging_worker` en las redes `admino` y `hotelignite` (está en el `docker-compose.yml`) |

**La credencial es una sola**, en dos formatos: `ak_live_<prefix>_<secret>` (el `secret`
que devuelve el PMS) o `ak_live_<prefix>.<secret>`. Colmexa guarda cada conector como
`client_id` + `client_secret` y los une con un punto, así que no hace falta nada más:
`client_id = ak_live_<prefix>`, `client_secret = <secret>`. El alta por API
(`POST /api/admin/v1/accommodations/{id}/api-keys`) ya devuelve los dos campos por separado.

**Verificado de punta a punta el 2026-10-21** por el widget: el agente listó las
habitaciones (`get_accommodation_info`), contestó disponibilidad del 10 al 12/11
(`check_availability`) y, con un "sí, dejá la consulta", creó la `Inquiry` con
`source=agent`, nombre, email y teléfono del formulario, sin volver a pedirlos. Las
habitaciones y el precio salen de los mocks (el agente lo aclara): falta cargarle datos reales.

---

## 1. Cargar la key de Anthropic en messaging

```bash
cd ~/projects/messaging
echo 'ANTHROPIC_API_KEY=sk-ant-...' >> .env
docker compose restart messaging_worker     # el agente corre en el worker
```

Sin esto el agente contesta "Tuve un problema para procesar tu mensaje" y el log dice
`falta ANTHROPIC_API_KEY`. El modelo sale de `ANTHROPIC_MODEL` (default `claude-opus-5`).

## 2. (Opcional) Probar el MCP solo

```bash
CID=$(docker exec api sed -n 1p /tmp/hi_key.txt); SEC=$(docker exec api sed -n 2p /tmp/hi_key.txt)
curl -s -X POST http://localhost/mcp/accommodation \
  -H "Authorization: Bearer $CID.$SEC" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_accommodation_info","arguments":{}}}'
```

Y desde messaging, que es lo que de verdad usa el agente:

```bash
docker compose exec -u www-data messaging_app php artisan connector:registrar hotel \
  --tenant=hotel-bonarda --url=http://nginx/mcp/accommodation \
  --client-id="$CID" --client-secret="$SEC" --bot=asistente --probar
```

## 3. Probar por el chat de la web

Una página cualquiera servida desde el origen permitido (con `file://` no anda nunca:
el widget exige `Origin`):

```bash
mkdir -p /tmp/widget-demo && cat > /tmp/widget-demo/index.html <<'HTML'
<!doctype html><html lang="es"><meta charset="utf-8"><title>Hotel Bonarda</title>
<body><h1>Hotel Bonarda</h1>
<script src="http://localhost:8081/widget.js" data-key="wk_live_e3456f34dff8c9356033692e43c01207"></script>
</body></html>
HTML
cd /tmp/widget-demo && python3 -m http.server 5555
```

Abrí `http://localhost:5555`, completá nombre/email/teléfono y probá, en orden:

1. Al abrir: el saludo *"¡Hola! Soy el asistente del Hotel Bonarda…"* (sin menú).
2. *"¿Qué habitaciones tienen?"* → usa `get_accommodation_info` (doble, triple, familiar).
3. *"¿Hay lugar para 2 del 10 al 12 de noviembre?"* → `check_availability`. Sin
   inventario cargado contesta `unknown`, y el agente tiene que ofrecer dejar la consulta.
4. *"Sí, dejá la consulta"* → `create_inquiry` con el nombre y el teléfono que ya cargaste
   en el formulario: no te los tiene que volver a pedir.

La respuesta tarda unos segundos: el POST devuelve sólo tu mensaje, el agente corre en
la cola y lo que contesta llega por el polling del widget (cada 3 s).

Sin navegador, lo mismo con curl:

```bash
K=wk_live_e3456f34dff8c9356033692e43c01207; B=http://localhost:8081/api/widget
H=(-H "X-Widget-Key: $K" -H "Origin: http://localhost:5555" -H 'Accept: application/json' -H 'Content-Type: application/json')
T=$(curl -s -X POST $B/session "${H[@]}" -d '{"name":"Ana","email":"ana@prueba.test","phone":"3874111222"}' | jq -r .data.session_token)
curl -s -X POST $B/messages "${H[@]}" -H "X-Widget-Session: $T" -d '{"body":"¿qué habitaciones tienen?"}' | jq .data.cursor
sleep 8; curl -s "$B/messages?after=<cursor>" "${H[@]}" -H "X-Widget-Session: $T" | jq '.data.messages[].body'
```

## 4. Verificar del lado de HotelIgnite

- **PMS → `/consultas`** con un usuario de la cuenta de Hotel Bonarda: la consulta con
  origen *Asistente IA* y el link "Responder por WhatsApp".
- **Mail `InquiryReceivedMail`**: va en cola (`QUEUE_CONNECTION=database`). Si no llega,
  mirar que haya un `queue:work` corriendo en `api` y la tabla `failed_jobs`.
- `last_used_at` de la key se actualiza en cada llamada.

## 5. Telegram

Hace falta un bot propio (BotFather) y una URL pública hacia messaging:

```bash
ngrok http 8081
docker compose exec -u www-data -e HOME=/tmp messaging_app php artisan tinker --execute='
$t = App\Models\Tenant::where("slug","hotel-bonarda")->firstOrFail();
app(App\Support\Tenancy::class)->set($t);
$c = App\Models\Channel::create([
  "tenant_id" => $t->id, "bot_id" => App\Models\Bot::where("slug","asistente")->value("id"),
  "type" => "telegram", "name" => "Telegram del hotel", "handle" => "@tu_bot",
  "webhook_token" => App\Models\Channel::newWebhookToken(), "status" => "pending", "is_active" => true,
  "credentials" => ["bot_token" => "TOKEN_DE_BOTFATHER", "secret_token" => Illuminate\Support\Str::random(32)],
]);
echo $c->id, PHP_EOL;'
docker compose exec -u www-data messaging_app php artisan telegram:webhook <id> --url=https://xxxx.ngrok-free.app
```

Abrí el bot en Telegram: el `/start` responde con el saludo del bot y lo demás va al agente.

## 6. WhatsApp

El agente de WhatsApp corre igual (mismo router), pero el único número de dev es el canal 5
del Hospital Oñativia, y un `phone_number_id` pertenece a un solo canal. Dos opciones:

- **Con un número de prueba propio** en Meta: crear un canal `whatsapp` para `hotel-bonarda`
  con `bot_id` = `asistente` y seguir `~/projects/messaging/docs/probar-webhook.md`. Todo el
  que escriba va al agente.
- **Sobre el número del hospital, sólo para tu celular**: registrar el conector también en
  `onativia` y habilitar tu `wa_id` (con el 9). Los pacientes siguen en el árbol. El prompt
  va a decir "asistente de Hospital Oñativia" porque ese canal no tiene bot agente.

  ```bash
  docker compose exec -u www-data messaging_app php artisan connector:registrar hotel \
    --tenant=onativia --url=http://nginx/mcp/accommodation \
    --client-id="$CID" --client-secret="$SEC" --habilitar=549387XXXXXXX --probar
  ```

En WhatsApp el teléfono del huésped (el `wa_id`) ya va en el prompt, así que `create_inquiry`
no lo pide.

---

## Si algo no anda

| Síntoma | Causa probable |
|---|---|
| "Tuve un problema para procesar tu mensaje" | Falta `ANTHROPIC_API_KEY`, la cuenta no tiene crédito ("credit balance is too low"), u otro error de Anthropic: ver `Anthropic respondio con error` en el log |
| El agente contesta pero no usa las tools | El worker no llega al MCP: `messaging_worker` fuera de la red `hotelignite` |
| `--probar` no lista nada / 401 | Key revocada, client_id/secret mal copiados, o el alojamiento dejó de estar `enabled` |
| 403 "El plan del alojamiento no incluye el asistente" | El plan no tiene `ai_assistant` (Free, Plan I…) |
| El widget muestra el menú del hospital | El canal no tiene `bot_id` del bot agente, o el bot está `paused` |
| El widget da 403 | `Origin` no está en `settings.allowed_origins` del canal |
| 502 en `localhost:8081` | Se recreó `messaging_app`: `docker compose restart messaging_nginx` |
| El agente no contesta nada | La conversación la tomó una persona (`status` `human`/`pending`), o el worker está caído |
| Cambié código y no se nota | `docker compose restart messaging_worker` |

Logs:

```bash
docker exec messaging_worker tail -f storage/logs/whatsapp-$(date +%F).log   # MCP, agente, envíos
docker exec api tail -f storage/logs/laravel.log
```

## Volver atrás

```bash
# HotelIgnite: Hotel Bonarda vuelve a Plan I y la key se revoca
docker exec -e HOME=/tmp api php artisan tinker --execute='
App\Models\Accommodation::findOrFail(14)->forceFill(["plan_id" => 1])->save();
App\Models\AccommodationApiKey::where("prefix","ak_live_zzw2hedl")->update(["revoked_at" => now()]);'
docker exec api rm -f /tmp/hi_key.txt
```

## Pendiente

- **Confirmación de `create_inquiry`.** La descripción de la tool le pide al modelo que
  confirme los datos con el huésped, pero la tool no publica `_meta.requires_confirmation`,
  así que el `ConfirmationGate` de Colmexa no la frena (aparece "confirmación: no" en
  `--probar`). Publicarlo haría que el huésped confirme dos veces. Falta decidir.
- Elegir el hotel piloto real y cargarle room types, inventario y tarifas: sin eso
  `check_availability` sale `unknown` y no hay precios.
- Pantalla "Asistente" en el PMS para generar la key (fase 5 del plan).
