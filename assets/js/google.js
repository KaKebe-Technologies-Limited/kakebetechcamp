/* Kakebe Tech Camp — "Continue with Google" (Google Identity Services callback) */
window.ktGoogleCredential = async function (response) {
  const cfg = window.KT_GOOGLE || {};
  const msg = document.getElementById('googleMsg');
  const say = (text, isError) => {
    if (!msg) return;
    msg.hidden = !text;
    msg.textContent = text || '';
    msg.classList.toggle('error', !!isError);
  };
  say('Signing you in with Google…');
  try {
    const fd = new FormData();
    fd.append('credential', response.credential);
    fd.append('csrf', cfg.csrf || '');
    fd.append('intent', cfg.intent || 'register');
    const res = await fetch(cfg.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
    const json = await res.json();
    if (json.ok && json.redirect) {
      say(json.action === 'login' ? 'Opening your dashboard…' : 'Opening your registration form…');
      window.location.href = json.redirect;
      return;
    }
    say(json.message || 'Google sign-in failed. Please try again.', true);
  } catch (e) {
    say('Network error — please try again.', true);
  }
};
