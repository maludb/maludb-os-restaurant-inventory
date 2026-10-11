/* Slice 5 — the source form's connector sub-forms: the chosen one shown, the others hidden (JavaScript off shows every sub-form with the
   chosen one marked); the intake's fields follow the intake. The upload's preview is htmx's (the form works without it). */
(function () {
  function sync() {
    var sel = document.getElementById('source-form-field-connector');
    if (!sel) return;
    document.querySelectorAll('.pos-subform').forEach(function (fs) { fs.hidden = fs.getAttribute('data-connector') !== sel.value; });
    var intake = document.getElementById('source-form-field-intake');
    if (intake) {
      var v = intake.value;
      [['source-form-field-email-from', 'email'], ['source-form-field-sftp-host', 'sftp'], ['source-form-field-sftp-path', 'sftp'], ['source-form-field-sftp-user', 'sftp']].forEach(function (p) {
        var el = document.getElementById(p[0]);
        if (el) { var row = el.closest('.row'); if (row) row.hidden = v !== p[1]; }
      });
      var addr = document.getElementById('source-form-intake-address');
      if (addr) addr.hidden = v !== 'email';
    }
  }
  document.addEventListener('change', function (e) { if (e.target && (e.target.id === 'source-form-field-connector' || e.target.id === 'source-form-field-intake')) sync(); });
  document.addEventListener('htmx:afterSettle', sync);
  if (document.readyState !== 'loading') sync(); else document.addEventListener('DOMContentLoaded', sync);
})();
