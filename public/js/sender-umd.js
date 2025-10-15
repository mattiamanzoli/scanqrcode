// Sender (telefono): usa Vue + vue-qrcode-reader UMD.
// Richiede: Vue globale e window.VueQrcodeReader già caricati (enqueue dal plugin).
(function(){
  const { createApp } = window.Vue || {};

  function getParam(name) {
    const m = new URLSearchParams(location.search).get(name);
    return m ? String(m) : '';
  }

  function extractId(content, mode) {
    if (mode === 'raw') return String(content || '').trim();
    try {
      const u = new URL(String(content));
      return u.searchParams.get('id') || (u.hash || '').replace(/^#?id=/,'') || '';
    } catch(e) {
      return String(content || '').trim();
    }
  }

  // --- Funzione Aggiunta per la Vibrazione (Feedback Tattile) ---
  function vibrateSuccess() {
    // 500ms è una vibrazione lunga per un feedback chiaro.
    if ('vibrate' in navigator) {
      navigator.vibrate(500); 
    }
  }
  // ------------------------------------------------------------------

  const Root = {
    template: `
      <div class="qrseat-sender-ui">
        <qrcode-stream @detect="onDetect" @decode="onDecodeFallback" @error="onError"></qrcode-stream>
        
        <div v-if="error" class="qrseat-error">{{ error }}</div>
        <div v-else-if="success" class="qrseat-success">{{ success }}</div>
        
        <div class="qrseat-row">
          <label>Sessione:</label>
          <input v-model="session" placeholder="session id" :disabled="processing" />
        </div>
        <div class="qrseat-row small">Suggerimento: scansiona il QR Code di pairing mostrato sul desktop.</div>
        <div class="qrseat-row">
          <button @click="toggleTorch" type="button" class="qrseat-btn" :disabled="processing">Attiva/Disattiva Torcia</button>
        </div>
        
        <div class="qrseat-row small" v-if="lastSentId">Ultimo ID inviato: <code>{{ lastSentId }}</code></div>
        <div class="qrseat-row small" v-if="processing">Stato: **Invio in corso...**</div>
      </div>
    `,
    data(){ return {
      error: null,
      success: null, 
      session: '',
      rest: '',
      mode: 'param',
      redirect: false,
      base: '',
      processing: false,
      lastSentId: ''
    };},
    mounted(){
      const el = document.getElementById('qrseat-sender');
      this.rest = el?.dataset?.rest || (window.QRSEAT_REST && QRSEAT_REST.base) || '';
      this.mode = el?.dataset?.mode || 'param';
      this.redirect = (el?.dataset?.redirect === '1');
      this.base = el?.dataset?.base || '';
      this.session = getParam('session') || '';
      if (!this.session) {
        this.error = 'Sessione mancante. Scansiona il QR Code di pairing sul desktop.';
      }
    },
    methods: {
      onError(err){ 
        console.error(err); 
        this.error = String(err); 
        this.success = null;
      },
      onDecodeFallback(content){
        this.handleContent(content);
      },
      onDetect(list){
        try{
          const first = Array.isArray(list) && list[0] ? list[0] : null;
          const content = typeof first === 'string' ? first : (first && first.rawValue) || '';
          this.handleContent(content);
        }catch(e){ this.onError(e); }
      },
      async handleContent(content){
        if (this.processing) return;
        const id = extractId(content, this.mode);
        
        if (!id) { this.error = 'ID non trovato nel QR'; return; }
        if (!this.session) { this.error = 'Sessione mancante. Impossibile inviare.'; return; }

        // 1. Vibrazione immediata alla lettura
        vibrateSuccess(); 
        
        this.processing = true; this.error = null; this.success = null;
        try{
          const resp = await fetch(this.rest.replace(/\/$/, '') + '/send', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ session: this.session, id })
          });
          const data = await resp.json();
          if (!data || data.ok !== true) throw new Error('Invio fallito. Controlla il log sul Receiver.');
          
          this.lastSentId = id;
          this.success = `ID "${id}" inviato con successo!`;
          
          if (this.redirect) {
            const base = this.base || '{id}';
            const url = base.replace('{id}', encodeURIComponent(id));
            location.href = url;
            return; 
          }
        } catch(e){
          this.error = 'Errore invio ID. Vedi console per dettagli.';
          console.error(e);
        } finally{
          // Piccolo delay per feedback visivo e evitare doppio invio
          setTimeout(()=>{ 
            this.processing = false; 
            if (!this.error) {
              setTimeout(() => { this.success = null; }, 2000);
            }
          }, 1200);
        }
      },
      toggleTorch(){
        try {
          const video = document.querySelector('video');
          const stream = video && video.srcObject;
          const track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
          if (!track) {
            this.error = 'Telecamera non accessibile per il controllo torcia.';
            return;
          }
          
          const capabilities = track.getCapabilities && track.getCapabilities();
          if (capabilities && capabilities.torch) {
            const currentConstraints = track.getConstraints && track.getConstraints();
            const currentTorch = currentConstraints.advanced ? currentConstraints.advanced.find(c => 'torch' in c)?.torch : false;
            
            const next = !currentTorch;
            track.applyConstraints({ advanced: [{ torch: next }] })
                 .then(() => { this.success = `Torcia ${next ? 'Accesa' : 'Spenta'}.`; })
                 .catch(e => { this.onError('Impossibile cambiare lo stato della torcia: ' + e.message); });
          } else {
            this.error = 'Torcia non supportata su questo dispositivo/browser.';
          }
        } catch(e){ 
          console.warn('Torch non supportata', e); 
          this.error = 'Errore generico torcia.';
        }
      }
    }
  };

  if (!createApp) return;
  const app = createApp(Root);
  if (window.VueQrcodeReader) { app.use(window.VueQrcodeReader); }
  app.mount('#qrseat-sender');
})();