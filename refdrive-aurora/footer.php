</main><!-- /#main -->

<footer class="rdpro-footer">
  <div class="rdpro-footer-inner">

    <div class="rdpro-footer-brand">
      <a href="<?php echo esc_url(home_url('/')); ?>" class="rdpro-logo">
        <span class="rdpro-logo-ref">ref</span><span class="rdpro-logo-drive">drive</span><span class="rdpro-logo-dot">.pro</span>
      </a>
      <p><?php aurora_txt('rd_txt_slogan', 'RefDrive.pro je platforma, která propojuje firmy s autoškoly. Zprostředkovává zákonné školení referentských řidičů. Vše online – certifikát bez zbytečného papírování.'); ?></p>
    </div>



  </div>

  <div class="rdpro-footer-bottom">
    <span>© <?php echo date('Y'); ?> <?php aurora_txt('rd_txt_copyright', 'RefDrive Pro. Všechna práva vyhrazena.'); ?></span>
    <span style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <a href="<?php echo esc_url(home_url('/kontakt/')); ?>" style="color:var(--au-text-3,#6b7280);font-size:.75rem;text-decoration:none">Kontakt</a>
      <a href="<?php aurora_url('rd_url_vop', '/vseobecne-podminky/'); ?>" style="color:var(--au-text-3,#6b7280);font-size:.75rem;text-decoration:none"><?php aurora_txt('rd_txt_footer_vop', 'VOP'); ?></a>
      <a href="<?php aurora_url('rd_url_gdpr', '/ochrana-osobnich-udaju/'); ?>" style="color:var(--au-text-3,#6b7280);font-size:.75rem;text-decoration:none"><?php aurora_txt('rd_txt_footer_gdpr', 'GDPR'); ?></a>
      <a href="<?php aurora_url('rd_url_faq', '/faq/'); ?>" style="color:var(--au-text-3,#6b7280);font-size:.75rem;text-decoration:none"><?php aurora_txt('rd_txt_footer_faq', 'FAQ'); ?></a>
      <span class="rdpro-footer-badge"><svg style="display:inline;vertical-align:middle;margin-right:4px" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg><?php aurora_txt('rd_txt_footer_ssl', 'SSL zabezpečeno'); ?></span>
    </span>
  </div>
</footer>


<?php
// Cookies lišta
$cookies_text = get_option('rd_txt_cookies', 'Tento web používá cookies pro zajištění funkčnosti a analýzu návštěvnosti. Kliknutím na „Přijmout" souhlasíte s jejich použitím.');
?>
<div id="rd-cookies" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#1a1728;border-top:1px solid #2d2a3e;padding:14px 20px;box-shadow:0 -4px 24px rgba(0,0,0,.5)" data-cookies>
  <div style="max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:space-between">
    <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.5;flex:1;min-width:200px">
      <?php echo esc_html($cookies_text); ?>
      <a href="<?php aurora_url('rd_url_cookies_vice', '/ochrana-osobnich-udaju/'); ?>" style="color:var(--au-violet,#7c3aed);margin-left:6px">Více informací</a>
    </p>
    <div style="display:flex;gap:8px;flex-shrink:0">
      <button onclick="rdCookiesSet('rejected')" style="background:none;border:1px solid #2d2a3e;color:#9ca3af;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px">Odmítnout</button>
      <button onclick="rdCookiesSet('accepted')" style="background:#7c3aed;border:none;color:#fff;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500">Přijmout</button>
    </div>
  </div>
</div>
<script>
function rdCookiesSet(val) {
  localStorage.setItem('rd_cookies', val);
  document.getElementById('rd-cookies').style.display = 'none';
}
(function() {
  var bar = document.getElementById('rd-cookies');
  if (!localStorage.getItem('rd_cookies')) {
    bar.style.display = 'block';
  }
  // Light mode
  if (document.documentElement.dataset.theme === 'light' || localStorage.getItem('rd_theme') === 'light') {
    bar.style.background = '#ffffff';
    bar.style.borderTopColor = '#e5e7eb';
    bar.style.boxShadow = '0 -4px 24px rgba(0,0,0,.1)';
    bar.querySelector('p').style.color = '#374151';
  }
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
