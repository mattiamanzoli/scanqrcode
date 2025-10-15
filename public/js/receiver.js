(function(){
  function getParam(name) {
    const m = new URLSearchParams(location.search).get(name);
    return m ? String(m) : '';
  }
  function randSession(){
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    let s = '';
    for (let i=0;i<22;i++){ s += chars[Math.floor(Math.random()*chars.length)]; }
    return s;
  }
  function ensureSession(autogen){
    let s = getParam('session');
    if (!s && autogen) {
      s = randSession();
      const u = new URL(location.href);
      u.searchParams.set('session', s);
      history.replaceState(null, '', u.toString());
    }
    return s;
  }
  async function poll(restBase, session, since){
    const url = restBase.replace(/\/$/,'') + '/next?session=' + encodeURIComponent(session) + '&since=' + (since||0);
    const r = await fetch(url, { cache: 'no-cache' });
    if (!r.ok) throw new Error('poll error: ' + r.statusText);
    return r.json();
  }
  function buildUrl(base, id){ return (base || '{id}').replace('{id}', encodeURIComponent(id)); }
  
  // Funzione di utilità per il Log Live
  function log(message, isError = false) {
    const el = document.getElementById('qrseat-log');
    if (!el) return;
    const now = new Date().toLocaleTimeString();
    const prefix = isError ? '[ERRORE]' : '[INFO]';
    const msg = `${now} ${prefix} ${message}\n`;
    // Inserisce il log in cima
    el.textContent = msg + el.textContent;
    // Trunca i log per evitare overflow
    if (el.textContent.length > 3000) el.textContent = el.textContent.substring(0, 3000); 
  }

  // Funzione per la generazione del QR Code di Pairing
  function generatePairingQr(url) {
    const el = document.getElementById('qrseat-qrcode-target');
    if (!el || typeof window.QRCode === 'undefined') {
        log('Libreria QRCode non caricata. Impossibile generare QR.', true);
        return;
    }
    el.innerHTML = ''; // Pulisce il contenitore
    try {
        new window.QRCode(el, {
            text: url,
            width: 200,
            height: 200,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : window.QRCode.CorrectLevel.H
        });
        log('QR Code di pairing generato.');
    } catch(e) {
        log('Errore generazione QR: ' + e.message, true);
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    const el = document.getElementById('qrseat-receiver');
    if (!el) return;

    // Funzione per copiare il testo
    function setupCopyButtons() {
        document.querySelectorAll('.qrseat-copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const targetEl = document.getElementById(targetId);
                const isRawText = this.dataset.rawText === '1';
                
                if (targetEl && navigator.clipboard) {
                    // Prende il contenuto di un link (href) o il contenuto di un tag (textContent)
                    const textToCopy = isRawText ? targetEl.href : targetEl.textContent.trim();

                    navigator.clipboard.writeText(textToCopy).then(() => {
                        const originalText = this.textContent;
                        this.textContent = 'Copiato!';
                        setTimeout(() => { this.textContent = originalText; }, 1500);
                    }).catch(err => {
                        log('Copia fallita: ' + err, true);
                    });
                }
            });
        });
    }

    const rest = el.dataset.rest || (window.QRSEAT_REST && QRSEAT_REST.base) || '';
    let base = el.dataset.base || '{id}';
    const target = el.dataset.target === '_blank' ? '_blank' : '_self';
    const polling = Math.max(400, parseInt(el.dataset.polling || '1200', 10));
    const autogen = el.dataset.autogen !== '0';

    const session = ensureSession(autogen);
    const label = document.getElementById('qrseat-session-label');
    const senderLink = document.getElementById('qrseat-sender-url');
    const statusIndicator = document.getElementById('qrseat-status-indicator');

    if (label) label.textContent = session || '—';
    setupCopyButtons(); // Attiva i pulsanti copia

    // Costruisci URL suggerito per Sender
    const senderUrl = (function(){
      const u = new URL(location.href);
      u.searchParams.set('session', session || '');
      let urlStr = u.toString();
      // Euristiche: sostituisce 'receiver' con 'sender' se presente nell'URL per un fallback migliore
      if (urlStr.includes('receiver') || urlStr.includes('recv')) {
        urlStr = urlStr.replace(/(receiver|recv)/i, 'sender');
      }
      return urlStr;
    })();
    if (senderLink) { senderLink.textContent = senderUrl; senderLink.href = senderUrl; }
    
    // Genera QR Code di pairing se la sessione è valida
    if (session) {
        generatePairingQr(senderUrl);
    } else {
        log('Sessione non valida o mancante. Polling disabilitato.', true);
        if (statusIndicator) statusIndicator.textContent = 'ERRORE: Sessione mancante.';
        return;
    }

    let since = 0;
    async function loop(){
      if (!session) return;
      try{
        if (statusIndicator) statusIndicator.textContent = 'In ascolto (prossima verifica in ' + (polling/1000).toFixed(1) + 's)...';
        const data = await poll(rest, session, since);

        if (data && typeof data.ver === 'number') {
          if (data.ver > since && data.id) {
            since = data.ver;
            const url = buildUrl(base, String(data.id));
            log(`[SUCCESSO] ID ricevuto: ${data.id} (versione: ${data.ver}). Reindirizzamento...`);
            if (statusIndicator) statusIndicator.textContent = 'ID RICEVUTO! Reindirizzamento...';

            // Piccolo delay prima del redirect per dare tempo al log di apparire
            setTimeout(() => {
                if (target === '_blank') {
                    window.open(url, '_blank', 'noopener');
                } else {
                    location.href = url;
                }
            }, 500);
            
            // Non terminare, ma ritardare il loop, se l'utente torna indietro il polling riparte
            // return; 
          } else {
            // Nessun nuovo ID trovato (ver <= since)
            const lastTime = data.time ? new Date(data.time * 1000).toLocaleTimeString() : 'N/A';
            log(`Polling OK. Ultimo ID (ver ${data.ver}) inviato alle ${lastTime}.`);
          }
        }
      } catch(e){
        log(`Errore di polling: ${e.message}. Riprovo.`, true);
        if (statusIndicator) statusIndicator.textContent = 'ERRORE. Riprovo...';
      }
      setTimeout(loop, polling);
    }
    log('Polling avviato. Intervallo: ' + polling + 'ms.');
    loop();
  });
})();