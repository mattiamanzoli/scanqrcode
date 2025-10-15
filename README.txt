=== WP QR → Seatable Bridge ===
Contributors: chatgpt, mattia
Tags: qr, seatable, pairing, redirect, scanner
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin che collega una pagina "sender" su smartphone (scansione QR) ad una pagina "receiver" su desktop in ascolto. Quando il sender invia un ID, la receiver reindirizza all'URL pubblico del record Seatable.

== Descrizione ==

- **Receiver (desktop)**: mostra una sessione, genera un **QR Code di pairing** per aprire il Sender sul telefono con la sessione precompilata, effettua polling via REST API e, alla ricezione di un ID, reindirizza al link finale (composto da `base` e `{id}`).
- **Sender (telefono)**: legge QR (via `vue-qrcode-reader`), estrae l'ID (da `?id=` o contenuto raw), vibra su lettura, invia via REST alla sessione attiva. Opzionalmente può anche reindirizzare il telefono allo stesso link finale.

== Shortcodes ==

`[qr_receiver base="https://seatable.tuo/view/row/{id}" target="_self" polling="1200" autogen="1"]`  
Parametri:
- `base`: template URL con `{id}` (default: opzione generale).
- `target`: `_self` oppure `_blank`.
- `polling`: intervallo in ms (>= 400).
- `autogen`: `1` per generare automaticamente la sessione se manca, `0` per richiederla nell'URL.

`[qr_sender mode="param" redirect="0" base=""]`  
Parametri:
- `mode`: `param` (estrae `?id=`) oppure `raw` (usa il contenuto completo del QR).
- `redirect`: `1` per reindirizzare anche il telefono al link finale.
- `base`: usato per il redirect del telefono (se `redirect=1`).

== Impostazioni ==

In **Impostazioni → QR → Seatable Bridge**:
- **Base URL**: es. `https://seatable.tuo/view/row/{id}` oppure semplicemente `{id}` se il QR contiene già l'URL completo.
- **Target redirect**: `_self` o `_blank`.
- **Intervallo polling**: consigliati 800–1500 ms.
- **TTL messaggi**: tempo di conservazione dell'ultimo ID per sessione.

== Endpoint REST ==

- `POST /wp-json/qrseat/v1/send` – body JSON: `{ "session": "abc", "id": "ROW123" }`
- `GET  /wp-json/qrseat/v1/next?session=abc&since=0` – ritorna `{ ok, ver, id?, time? }`

== Requisiti ==

- HTTPS per accedere alla fotocamera su mobile.
- Caricati via CDN: Vue 3 e `vue-qrcode-reader` per il sender; `qrcodejs` per il QR di pairing.

== Troubleshooting ==

- **"Impossibile caricare lo scanner"**: assicurarsi che la pagina sia su HTTPS e che il browser abbia i permessi camera.
- **Non si vede il QR di pairing**: verificare che la libreria `qrcodejs` sia caricata (rete/CDN).
- **Il redirect avviene su un'altra pagina**: il receiver costruisce l'URL con `base` e `{id}` e reindirizza secondo `target`.
- **Vuoi cambiare il link del telefono**: nel sender imposta `redirect="1"` e `base="..."` nello shortcode o nelle impostazioni generali.

== Installazione ==

1. Scarica il file ZIP e caricalo in **Plugin → Aggiungi nuovo → Carica plugin**.
2. Attiva il plugin.
3. Crea due pagine: **Receiver** con `[qr_receiver]` e **Sender** con `[qr_sender]`.
4. Apri la pagina **Receiver** su desktop, scansiona il QR di pairing con lo smartphone e prova una scansione di un QR che contenga l'ID o l'URL (a seconda della configurazione).

== Changelog ==

= 1.1.0 =
- Pairing QR integrato nel receiver con pulsanti copia.
- Log live, indicatori di stato e vibrazione lato sender.
- Opzioni admin per base URL, target, polling e TTL.
