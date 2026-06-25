<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lovelace — Download &amp; Early Access</title>
<style>
:root {
  --bg: #080b12;
  --panel: #111820;
  --accent: #f472b6;
  --accent-bright: #f9a8d4;
  --accent-dim: rgba(244, 114, 182, 0.12);
  --accent-glow: rgba(244, 114, 182, 0.06);
  --text: #e8eaed;
  --text-muted: #8b949e;
  --text-dim: #555d66;
  --border: rgba(255, 255, 255, 0.06);
  --card-bg: rgba(17, 24, 32, 0.80);
  --green: #22c55e;
  --red: #ef4444;
  --radius: 16px;
  --font: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  --transition: 0.22s ease;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; -webkit-font-smoothing: antialiased; }
body {
  min-height: 100vh;
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  line-height: 1.6;
}
body::before {
  content: '';
  position: fixed; top: -200px; left: 50%; transform: translateX(-50%);
  width: 1000px; height: 600px;
  background: radial-gradient(ellipse, var(--accent-glow), transparent 70%);
  pointer-events: none; z-index: 0;
}
.site {
  position: relative; z-index: 1;
  max-width: 640px;
  margin: 0 auto;
  padding: 32px 20px 80px;
}

/* ── Nav ── */
.nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 48px;
}
.nav-back {
  font-size: 0.8rem;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  text-decoration: none;
  color: var(--text-dim);
  transition: color var(--transition);
}
.nav-back:hover { color: var(--accent); }
.nav-brand {
  font-size: 0.8rem;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--text-dim);
}

/* ── Hero ── */
.hero {
  text-align: center;
  padding: 32px 20px 40px;
}
.hero-title {
  font-size: 3rem;
  font-weight: 800;
  letter-spacing: 5px;
  text-transform: uppercase;
  color: var(--accent);
}
.hero-tagline {
  margin-top: 12px;
  font-size: 1.15rem;
  color: var(--text-muted);
  letter-spacing: 1px;
}

/* ── Cards ── */
.card {
  background: var(--card-bg);
  border: 1px solid rgba(244, 114, 182, 0.35);
  border-radius: var(--radius);
  padding: 32px 24px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.card::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at center, var(--accent-glow), transparent 70%);
  pointer-events: none;
}
.card h2 {
  font-size: 1.15rem;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--accent);
  text-align: center;
  margin-bottom: 16px;
  position: relative;
}

/* ── Download buttons ── */
.dl-buttons {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
  position: relative;
}
.btn-dl {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  border-radius: 10px;
  font-size: 0.95rem;
  font-weight: 600;
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: all var(--transition);
}
.btn-dl.android {
  background: #22c55e;
  color: #fff;
}
.btn-dl.android:hover {
  background: #16a34a;
  box-shadow: 0 0 16px rgba(34, 197, 94, 0.3);
}
.btn-dl.apple {
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-dim);
  cursor: not-allowed;
  opacity: 0.6;
}

/* ── Tester section ── */
.tester-section {
  text-align: center;
  position: relative;
}
.tester-desc {
  color: var(--text-muted);
  font-size: 0.85rem;
  margin-bottom: 16px;
}
.count-display {
  margin-bottom: 20px;
}
.count-number {
  font-size: 2rem;
  font-weight: 800;
}
.count-label {
  color: var(--text-muted);
  font-size: 0.9rem;
}
.form-row {
  max-width: 340px;
  margin: 0 auto 12px;
}
.form-row input {
  width: 100%;
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.1);
  background: rgba(255,255,255,0.05);
  color: var(--text);
  font-size: 0.9rem;
  outline: none;
  transition: border-color var(--transition);
}
.form-row input:focus {
  border-color: var(--accent);
}
.btn-claim {
  display: inline-block;
  padding: 12px 32px;
  border-radius: 10px;
  font-size: 0.95rem;
  font-weight: 700;
  border: 2px solid var(--accent);
  background: var(--accent-dim);
  color: var(--accent);
  cursor: pointer;
  transition: all var(--transition);
  text-transform: uppercase;
  letter-spacing: 1px;
}
.btn-claim:hover {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 0 20px rgba(244, 114, 182, 0.3);
}
.btn-claim:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  box-shadow: none;
}
.btn-claim:disabled:hover {
  background: var(--accent-dim);
  color: var(--accent);
}

/* ── License result ── */
.license-result {
  text-align: center;
  position: relative;
}
.license-key {
  display: block;
  font-family: 'Courier New', Courier, monospace;
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--green);
  background: rgba(34, 197, 94, 0.08);
  border: 1px solid rgba(34, 197, 94, 0.25);
  border-radius: 8px;
  padding: 14px 20px;
  margin: 12px auto;
  max-width: 400px;
  word-break: break-all;
  letter-spacing: 1px;
}
.btn-copy {
  display: inline-block;
  padding: 8px 20px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  border: 1px solid rgba(255,255,255,0.15);
  background: rgba(255,255,255,0.06);
  color: var(--text-muted);
  cursor: pointer;
  transition: all var(--transition);
  margin-top: 4px;
}
.btn-copy:hover {
  background: rgba(255,255,255,0.12);
  color: var(--text);
}
.license-note {
  color: var(--text-muted);
  font-size: 0.85rem;
  margin-top: 12px;
}

/* ── Features list ── */
.features {
  position: relative;
}
.features ul {
  list-style: none;
  padding: 0;
}
.features li {
  padding: 8px 0;
  color: var(--text-muted);
  font-size: 0.9rem;
  border-bottom: 1px solid var(--border);
  position: relative;
  padding-left: 24px;
}
.features li:last-child { border-bottom: none; }
.features li::before {
  content: '\2713';
  position: absolute;
  left: 0;
  color: var(--green);
  font-weight: 700;
}

/* ── Error message ── */
.error-msg {
  color: var(--red);
  font-size: 0.85rem;
  margin-top: 8px;
  display: none;
}

/* ── Footer ── */
.footer {
  text-align: center;
  margin-top: 32px;
  padding-top: 24px;
  border-top: 1px solid var(--border);
  color: var(--text-dim);
  font-size: 0.75rem;
  letter-spacing: 0.5px;
}
.footer a {
  color: var(--text-muted);
  text-decoration: none;
  transition: color var(--transition);
}
.footer a:hover { color: var(--text); }

@media (max-width: 600px) {
  .hero-title { font-size: 2rem; letter-spacing: 3px; }
  .card { padding: 24px 16px; }
  .license-key { font-size: 0.95rem; padding: 12px 14px; }
  .dl-buttons { flex-direction: column; align-items: center; }
}
</style>
</head>
<body>

<div class="site">

  <div class="nav">
    <a href="/lovelace/" class="nav-back">&larr; Back</a>
    <span class="nav-brand">Lovelace</span>
  </div>

  <div class="hero">
    <h1 class="hero-title">Lovelace</h1>
    <div class="hero-tagline">Download &amp; Get Early Access</div>
  </div>

  <!-- Download -->
  <div class="card">
    <h2>Download the App</h2>
    <div class="dl-buttons">
      <a href="https://play.google.com/apps/internaltest/4700578757188707220" class="btn-dl android">
        &#9658; Android (Google Play)
      </a>
      <a href="https://testflight.apple.com/join/qArt77xQ" class="btn-dl apple" style="background:rgba(255,255,255,0.08);color:var(--text);opacity:1;cursor:pointer;text-decoration:none;">
        &#63743; iOS (TestFlight)
      </a>
    </div>
  </div>

  <!-- What you get -->
  <div class="card features">
    <h2>What You Get</h2>
    <ul>
      <li>Privacy-first dating &mdash; ZK age proof, no documents stored</li>
      <li>Verified humans only &mdash; Identus DID + face uniqueness</li>
      <li>Real-time chat &amp; gifting</li>
      <li>$LILY token rewards for positive behaviour</li>
      <li>18 languages supported</li>
    </ul>
  </div>

  <div class="footer">
    &copy; 2026 <a href="/">Posada.io</a> / Lovelace &mdash; Built on Cardano
  </div>

</div>

</body>
</html>
