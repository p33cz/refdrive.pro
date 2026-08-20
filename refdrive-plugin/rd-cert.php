<?php
$wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (!file_exists($wp_load)) { http_response_code(404); exit; }
require_once($wp_load);

if (!session_id()) @session_start();

$kod   = sanitize_text_field($_GET['kod'] ?? '');
$nonce = sanitize_text_field($_GET['nonce'] ?? '');
$access = sanitize_text_field($_GET['access'] ?? '');

// Ověření přístupu
$nonce_ok = $nonce && wp_verify_nonce($nonce, 'rd_cert_dl_' . $kod);

// Jednorázový přístupový klíč přes transient (platí 1 hodinu)
$access_ok = false;
if ($access && $kod) {
    $transient_kod = get_transient('rd_cert_access_' . $access);
    if ($transient_kod && $transient_kod === $kod) {
        $access_ok = true;
        // Nesmazat transient — umožnit opakované zobrazení v rámci platnosti
    }
}

// Admin/firma nonce nebo platný access klíč
if (!$kod || (!$nonce_ok && !$access_ok)) {
    // Zkusit ještě session (firma prohlíží certifikát zaměstnance)
    $firma_ok = !empty($_SESSION['rd_firma_id']);
    if (!$firma_ok) { http_response_code(403); exit('Neplatný přístup'); }
}

global $wpdb;
$r = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}rd_ridici WHERE kod=%s AND uspech=1 ORDER BY datum_skoleni DESC LIMIT 1", $kod
));
if (!$r) { http_response_code(404); exit('Certifikát nenalezen'); }

$je_admin  = current_user_can('manage_options') || current_user_can('refdrive_access');
$je_firma  = false;
$je_student = false;

// Ověření firmy
if (!$je_admin && !empty($_SESSION['rd_firma_id'])) {
    $firma_row = $wpdb->get_row($wpdb->prepare(
        "SELECT email FROM {$wpdb->prefix}rd_firmy WHERE id=%d LIMIT 1",
        intval($_SESSION['rd_firma_id'])
    ));
    if ($firma_row) {
        $firma_kod = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rd_kody WHERE kod=%s AND firma_email=%s LIMIT 1",
            $kod, $firma_row->email
        ));
        if ($firma_kod) $je_firma = true;
    }
}

// Ověření studenta — stačí platný rd_kod v session
if (!$je_admin && !$je_firma) {
    $session_kod = $_SESSION['rd_kod'] ?? $_SESSION['rd_cert_kod'] ?? '';
    if (!empty($session_kod) && $session_kod === $kod) {
        $je_student = true;
    }
}

if (!$je_admin && !$je_firma && !$je_student && !$access_ok) { http_response_code(403); exit('Přístup odepřen'); }

if (empty($r->cert_uuid)) {
    $uuid = wp_generate_uuid4();
    $wpdb->update("{$wpdb->prefix}rd_ridici", ['cert_uuid' => $uuid], ['id' => $r->id]);
    $r->cert_uuid = $uuid;
}

$jmeno    = esc_html($r->jmeno);
$firma    = esc_html($r->firma_nazev);
$datum    = date('d. m. Y', strtotime($r->datum_skoleni));
$score    = intval($r->score);
$cert_id  = strtoupper($r->kod);
$trvani   = !empty($r->trvani_min) && $r->trvani_min > 0 ? $r->trvani_min . ' min' : 'absolvováno online';
$ts       = strtotime($r->datum_skoleni);
$validity = date('d. m. Y', strtotime('+2 years', $ts));
$verify   = home_url('/overit/?id=' . $r->cert_uuid);
$qr_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=70x70&data=' . urlencode($verify);

// v6.0.0 — načíst autoškolu jako pořadatele školení
$poradatel = '';
if (!empty($r->autoskola_id) && defined('RD_TABLE_AUTOSKOLY')) {
    $as_row = $wpdb->get_row($wpdb->prepare(
        "SELECT nazev, mesto, ico FROM " . RD_TABLE_AUTOSKOLY . " WHERE id=%d LIMIT 1",
        intval($r->autoskola_id)
    ));
    if ($as_row) {
        $poradatel = esc_html($as_row->nazev);
        if ($as_row->mesto) $poradatel .= ', ' . esc_html($as_row->mesto);
    }
}
if (!$poradatel) $poradatel = esc_html(get_bloginfo('name'));

$lekce = $wpdb->get_results("SELECT nazev FROM {$wpdb->prefix}rd_lekce ORDER BY poradi ASC");
if (empty($lekce)) {
    $nn = ['Kdo je referentský řidič','Legislativní základ','Odpovědnost za škodu','Doklady a způsobilost','Kontrola vozidla před jízdou','Bezpečnostní přestávky','Bezpečná jízda','Dopravní nehoda','Alkohol a únava','Zimní provoz 2026'];
    $lekce = array_map(function($n) { return (object)['nazev'=>$n]; }, $nn);
}
$total = count($lekce);
$half  = (int)ceil($total / 2);
$left  = $right = '';
foreach ($lekce as $i => $l) {
    $n    = esc_html($l->nazev);
    $item = '<div class="osnova-item"><span class="osnova-num">' . ($i+1) . '.</span> ' . $n . '</div>';
    if ($i < $half) $left .= $item; else $right .= $item;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=500">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=DM+Serif+Display&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { width: 100%; margin: 0; padding: 0; background: #e8e4f0; font-family: Inter, Arial, sans-serif; }
  #wrap { display: flex; justify-content: center; }
  .page { background: #fff; width: 500px; position: relative; overflow: hidden; border: 1px solid #d1d5db; transform-origin: center center; }
  @media screen and (min-width: 521px) {
    html, body { height: 100%; }
    #wrap { position: fixed; top: 0; left: 0; width: 100%; height: 100%; align-items: center; }
  }
  .deco { position: absolute; pointer-events: none; }
  .deco-tl { top: -60px; left: -60px; width: 200px; height: 200px; border-radius: 50%; border: 40px solid rgba(124,58,237,.06); }
  .deco-br { bottom: -80px; right: -80px; width: 260px; height: 260px; border-radius: 50%; border: 50px solid rgba(219,39,119,.05); }
  .deco-tr { top: 80px; right: -40px; width: 120px; height: 120px; border-radius: 50%; border: 25px solid rgba(124,58,237,.04); }
  .seal { position: absolute; right: 56px; top: 260px; width: 100px; height: 100px; opacity: .12; }
  .seal svg { width: 100%; height: 100%; }
  .bar { height: 8px; background: linear-gradient(90deg, #7c3aed, #db2777); }
  .wrap { padding: 28px 48px 22px; position: relative; z-index: 1; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 12px; border-bottom: 2px solid #7c3aed; margin-bottom: 8px; }
  .logo { font-size: 22px; font-weight: 900; color: #7c3aed; letter-spacing: -1px; }
  .logo em { color: #db2777; font-style: normal; }
  .logo-sub { font-size: 10px; color: #9ca3af; margin-top: 3px; font-weight: 500; }
  .eyebrow { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 3px; color: #9ca3af; margin-bottom: 6px; }
  .title { font-family: 'DM Serif Display', serif; font-size: 25px; color: #111827; margin-bottom: 2px; font-weight: 400; }
  .udeluje { font-size: 13px; color: #6b7280; margin: 8px 0 4px; }
  .jmeno { font-family: 'DM Serif Display', serif; font-size: 30px; color: #7c3aed; margin-bottom: 4px; font-weight: 400; }
  .jmeno-line { height: 2px; background: linear-gradient(90deg, transparent, #7c3aed, #db2777, transparent); margin-bottom: 10px; }
  table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table.meta td { padding: 5px 0; border-bottom: 1px solid #f3f4f6; font-size: 12px; vertical-align: middle; }
  .lbl { color: #6b7280; width: 42%; }
  .val { font-weight: 700; color: #111827; }
  .score-box { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 2px solid #34d399; border-radius: 6px; padding: 4px 18px; font-size: 18px; font-weight: 900; color: #065f46; display: inline-block; }
  .cert-id-wrap { background: #f8f6ff; border: 1px solid #ede9fe; border-radius: 8px; padding: 10px 16px; display: inline-flex; align-items: center; gap: 12px; margin-bottom: 6px; }
  .cert-id-label { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1.5px; }
  .cert-id-num { font-family: monospace; font-size: 18px; font-weight: 900; color: #7c3aed; letter-spacing: 3px; }
  .validity-chip { display: inline-flex; align-items: center; gap: 6px; background: #fef9c3; border: 1px solid #fde68a; border-radius: 99px; padding: 4px 12px; font-size: 10px; color: #92400e; font-weight: 600; margin-bottom: 8px; }
  .osnova-wrap { background: #f9f8ff; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; border: 1px solid #ede9fe; }
  .osnova-title { font-size: 9.5px; text-transform: uppercase; letter-spacing: 2px; color: #9ca3af; font-weight: 700; margin-bottom: 6px; line-height: 1.5; }
  .osnova-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
  .osnova-col { display: flex; flex-direction: column; }
  .osnova-item { padding: 2px 0; border-bottom: 1px solid #f0eeff; font-size: 11px; color: #374151; display: flex; gap: 6px; }
  .osnova-num { color: #a78bfa; font-weight: 700; min-width: 14px; margin-right: 2px; }
  .signature-area { display: flex; gap: 40px; margin-bottom: 10px; }
  .signature-box { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; }
  .signature-date { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 6px; }
  .signature-line { height: 1px; background: #d1d5db; margin-bottom: 5px; }
  .signature-label { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; }
  .footer { display: flex; justify-content: space-between; align-items: flex-start; border-top: 1px solid #e5e7eb; padding-top: 10px; gap: 12px; }
  .legal { font-size: 8.5px; color: #9ca3af; line-height: 1.7; text-align: justify; flex: 1; }
  .legal strong { color: #6b7280; }
  .qr-wrap { text-align: center; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; }
  .qr-box { width: 70px; height: 70px; border: 1px solid #e5e7eb; border-radius: 5px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px; overflow: hidden; }
  .qr-box img { width: 64px; height: 64px; }
  .qr-label { font-size: 8px; color: #9ca3af; }
  .bar-bottom { height: 7px; background: linear-gradient(90deg, #7c3aed, #db2777); }

  @page { size: A4 portrait; margin: 0; }
  @media print {
    html, body { width: 794px !important; margin: 0 !important; padding: 0 !important; background: white !important; display: block !important; }
    #wrap { all: unset !important; display: flex !important; justify-content: center !important; align-items: center !important; width: 794px !important; height: 1123px !important; }
    .page { transform-origin: center center !important; border: 1px solid #d1d5db !important; }
    .rd-print-btn, .print-hint { display: none !important; }
  }
  .rd-print-btn { background: none; border: 1px solid #d1d5db; border-radius: 6px; padding: 5px 12px; font-size: 10px; color: #6b7280; cursor: pointer; font-family: Arial, sans-serif; white-space: nowrap; }
  .rd-print-btn:hover { border-color: #7c3aed; color: #7c3aed; }
</style>
</head>
<body>
<div id="wrap"><div class="page" id="rd-cert-page">
  <div class="deco deco-tl"></div>
  <div class="deco deco-br"></div>
  <div class="deco deco-tr"></div>
  <div class="seal">
    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
      <circle cx="50" cy="50" r="48" fill="none" stroke="#7c3aed" stroke-width="2"/>
      <circle cx="50" cy="50" r="40" fill="none" stroke="#7c3aed" stroke-width="1"/>
      <text x="50" y="36" text-anchor="middle" font-size="7" font-family="Arial" font-weight="bold" fill="#7c3aed">REFDRIVE.PRO</text>
      <text x="50" y="52" text-anchor="middle" font-size="6" font-family="Arial" fill="#7c3aed">ŠKOLENÍ ŘIDIČŮ</text>
      <text x="50" y="64" text-anchor="middle" font-size="6" font-family="Arial" fill="#7c3aed">2026</text>
      <path d="M50 20 L52 26 L58 26 L53 30 L55 36 L50 32 L45 36 L47 30 L42 26 L48 26 Z" fill="#7c3aed" opacity=".5"/>
    </svg>
  </div>
  <div class="bar"></div>
  <div class="wrap">
    <div class="header">
      <div>
        <div class="logo">ref<em>drive</em>.pro</div>
        <div class="logo-sub">Online školení referentských řidičů</div>
      </div>
      <div style="text-align:right">
        <button class="rd-print-btn" onclick="window.print()">&#8659; Uložit jako PDF</button>
        <div class="print-hint" style="font-size:8px;color:#9ca3af;margin-top:4px;text-align:right">V dialogu tisku zvolte<br><strong>Uložit jako PDF</strong></div>
      </div>

    </div>
    <div class="eyebrow" style="text-align:center">Osvědčení o absolvování</div>
    <div class="title" style="text-align:center">Školení řidičů referentů</div>
    <div class="udeluje" style="text-align:center">úspěšně absolvoval</div>
    <div class="jmeno" style="text-align:center"><?php echo $jmeno; ?></div>
    <div class="jmeno-line"></div>
    <div class="validity-chip">⏱ Doporučené opakování: do <?php echo $validity; ?></div>
    <table class="meta">
      <tr><td class="lbl">Zaměstnavatel</td><td class="val"><?php echo $firma; ?></td></tr>
      <tr><td class="lbl">Datum absolvování</td><td class="val"><?php echo $datum; ?></td></tr>
      <tr><td class="lbl">Délka školení</td><td class="val"><?php echo esc_html($trvani); ?></td></tr>
      <tr><td class="lbl">Výsledek závěrečného testu</td><td class="val"><span class="score-box"><?php echo $score; ?> %</span></td></tr>
      <tr>
        <td class="lbl">Číslo certifikátu</td>
        <td class="val">
          <div class="cert-id-wrap">
            <div>
              <div class="cert-id-label">Evidenční číslo</div>
              <div class="cert-id-num"><?php echo $cert_id; ?></div>
            </div>
          </div>
        </td>
      </tr>
    </table>
    <div class="osnova-wrap">
      <div class="osnova-title">Osnova školení dle<br>§ 349 zák. č. 262/2006 Sb., ZP<br>NV č. 168/2002 Sb.</div>
      <div class="osnova-grid"><div class="osnova-col"><?php echo $left; ?></div><div class="osnova-col"><?php echo $right; ?></div></div>
    </div>
    <div class="signature-area">
      <div class="signature-box">
        <div class="signature-date" style="font-size:11px;color:#374151"><?php echo $poradatel; ?></div>
        <div class="signature-line"></div>
        <div class="signature-label">Pořadatel školení</div>
      </div>
      <div class="signature-box">
        <div class="signature-date"><?php echo $datum; ?></div>
        <div class="signature-line"></div>
        <div class="signature-label">Datum vystavení</div>
      </div>
    </div>
    <div class="footer">
      <div class="legal">Školení proběhlo formou online e-learningu v souladu s <strong>§ 103 a § 349 zákona č. 262/2006 Sb.</strong> (ZP) a <strong>nařízením vlády č. 168/2002 Sb.</strong> E-learning je plně uznatelný dozorovými orgány (MD ČR). Pravost certifikátu ověřte naskenováním QR kódu nebo na odkazu uvedeném pod QR kódem.</div>
      <div class="qr-wrap">
        <div class="qr-box"><img loading="eager" src="<?php echo esc_url($qr_url); ?>" alt="QR"></div>
        <div class="qr-label">Ověřit pravost</div>
        <div style="font-size:7px;word-break:break-all;max-width:100px;margin-top:3px"><a href="<?php echo esc_url($verify); ?>" style="color:#7c3aed"><?php echo esc_html($verify); ?></a></div>
      </div>
    </div>
  </div>
  <div class="bar-bottom"></div>
</div></div>
<script>
  var nativeW = 500;
  var nativeH = 0;

  function scaleDesktop() {
    var page = document.getElementById('rd-cert-page');
    if (window.innerWidth <= 520) {
      page.style.transform = '';
      return;
    }
    if (!nativeH) {
      page.style.transform = '';
      nativeH = page.offsetHeight;
    }
    var s = Math.min(window.innerWidth / nativeW, window.innerHeight / nativeH) * 0.95;
    page.style.transform = 'scale(' + s + ')';
  }

  window.addEventListener('load', function() {
    var page = document.getElementById('rd-cert-page');
    page.style.transform = '';
    nativeH = page.offsetHeight;
    scaleDesktop();
  });
  window.addEventListener('resize', scaleDesktop);

  window.addEventListener('beforeprint', function() {
    var page = document.getElementById('rd-cert-page');
    var wrap = document.getElementById('wrap');
    var a4w = 794, a4h = 1123, margin = 40;
    page.style.transform = '';
    var certH = page.offsetHeight;
    var s = Math.min((a4w - margin*2) / nativeW, (a4h - margin*2) / certH);
    page.style.transform = 'scale(' + s + ')';
    page.style.transformOrigin = 'center center';
    wrap.style.width = a4w + 'px';
    wrap.style.height = a4h + 'px';
    wrap.style.position = 'static';
  });
  window.addEventListener('afterprint', function() {
    var wrap = document.getElementById('wrap');
    wrap.style.width = '';
    wrap.style.height = '';
    wrap.style.position = '';
    setTimeout(scaleDesktop, 300);
  });
</script>
</body>
</html>
