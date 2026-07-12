<?php
// /plugins/form-builder/public/render.php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

function fb_h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Render a single (non-container) field. Returns '' for hidden/unknown types.
function fb_render_field_html(array $f, string $slug): string {
    $types = fb_field_types();
    $type = (string)$f['type'];
    $meta = $types[$type] ?? null;
    if ($meta === null || !empty($meta['container']) || !empty($f['is_hidden'])) return '';
    $key = (string)$f['field_key'];
    $label = (string)$f['label'];
    $req = !empty($f['required']);
    $valid = fb_field_validation($f);
    $maxBytes = (int)($valid['max_bytes'] ?? 5 * 1024 * 1024);
    $id = 'fb-' . $slug . '-' . $key;

    ob_start(); ?>
    <div class="fb-field" data-key="<?= fb_h($key) ?>">
      <?php if (!empty($meta['display'])): ?>
        <?php if ($type === 'heading'):
          $fs = fb_field_settings($f);
          $lvl = in_array(($fs['level'] ?? ''), FB_HEADING_LEVELS, true) ? $fs['level'] : 'h2'; ?>
        <<?= $lvl ?> class="fb-heading fb-<?= $lvl ?>"><?= fb_h($label) ?></<?= $lvl ?>>
        <?php elseif ($type === 'paragraph'): ?><div class="fb-paragraph"><?= nl2br(fb_h($label)) ?></div>
        <?php elseif ($type === 'richtext'):
          $fs = fb_field_settings($f); ?>
        <div class="fb-richtext"><?= (string)($fs['html'] ?? '') ?></div>
        <?php elseif ($type === 'raw_html'):
          $fs = fb_field_settings($f); ?>
        <div class="fb-rawhtml"><?= (string)($fs['html'] ?? '') ?></div>
        <?php elseif ($type === 'image_block'):
          $fs = fb_field_settings($f);
          $url = (string)($fs['url'] ?? '');
          if ($url !== ''):
            $w = (string)($fs['width'] ?? '');
            $style = in_array($w, ['25', '50', '75'], true) ? ' style="max-width:' . $w . '%"' : ''; ?>
        <figure class="fb-image"<?= $style ?>>
          <img src="<?= fb_h($url) ?>" alt="<?= fb_h((string)($fs['alt'] ?? '')) ?>" loading="lazy">
          <?php if (trim((string)($fs['caption'] ?? '')) !== ''): ?><figcaption><?= fb_h((string)$fs['caption']) ?></figcaption><?php endif; ?>
        </figure>
          <?php endif; ?>
        <?php else: ?><hr class="fb-divider"><?php endif; ?>
      <?php elseif (!empty($meta['file'])): ?>
        <label class="fb-label"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <div class="fb-drop" data-max="<?= $maxBytes ?>" data-image="<?= !empty($meta['image']) ? '1' : '0' ?>">
          <?php if (!empty($meta['image'])): ?>
          <input type="file" name="<?= fb_h($key) ?>" accept="image/*" <?= $req ? 'required' : '' ?>>
          <img class="up-preview" alt="">
          <div class="up-ic">&#128444;</div>
          <?php else: ?>
          <input type="file" name="<?= fb_h($key) ?>" <?= $req ? 'required' : '' ?>>
          <div class="up-ic">&#8682;</div>
          <?php endif; ?>
          <div class="up-t">Drop file here or click to browse</div>
          <div class="up-s">Max <?= round($maxBytes / 1048576, 1) ?> MB</div>
        </div>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'textarea'): ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <textarea id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" placeholder="<?= fb_h($f['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?>></textarea>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'select'): ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <select id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" <?= $req ? 'required' : '' ?>>
          <option value="" disabled selected><?= fb_h($f['placeholder'] ?? '-- Select --') ?></option>
          <?php foreach (fb_field_options($f) as $o): ?>
          <option value="<?= fb_h($o['value']) ?>"><?= fb_h($o['label'] . ($o['price'] > 0 ? ' (+' . fb_format_rupiah($o['price']) . ')' : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php elseif ($type === 'radio' || $type === 'checkbox'): ?>
        <span class="fb-label" style="display:block"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></span>
        <div class="fb-choices">
          <?php foreach (fb_field_options($f) as $o): ?>
          <label class="fb-choice">
            <input type="<?= $type ?>" name="<?= fb_h($key) ?><?= $type === 'checkbox' ? '[]' : '' ?>" value="<?= fb_h($o['value']) ?>" <?= ($req && $type === 'radio') ? 'required' : '' ?>>
            <span><?= fb_h($o['label']) ?></span>
            <?php if ($o['price'] > 0): ?><span class="price">+<?= fb_h(fb_format_rupiah($o['price'])) ?></span><?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php else:
        $inputType = in_array($type, ['email', 'tel', 'number', 'date'], true) ? $type : 'text';
        $attrs = '';
        if (isset($valid['min']) && $valid['min'] !== '') $attrs .= ' min="' . fb_h((string)$valid['min']) . '"';
        if (isset($valid['max']) && $valid['max'] !== '') $attrs .= ' max="' . fb_h((string)$valid['max']) . '"';
        if (!empty($valid['maxlength'])) $attrs .= ' maxlength="' . (int)$valid['maxlength'] . '"';
        ?>
        <label class="fb-label" for="<?= fb_h($id) ?>"><?= fb_h($label) ?> <?= $req ? '<span class="req">*</span>' : '' ?></label>
        <input type="<?= $inputType ?>" id="<?= fb_h($id) ?>" name="<?= fb_h($key) ?>" placeholder="<?= fb_h($f['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?><?= $attrs ?>>
        <?php if (!empty($f['help_text'])): ?><div class="fb-help"><?= fb_h($f['help_text']) ?></div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function fb_render_form(PDO $pdo, array $form): string {
    $formId = (int)$form['id'];
    $slug = (string)$form['slug'];
    $settings = fb_form_settings($form);
    $tree = fb_get_tree($pdo, $formId);
    $allFields = fb_flat_fields(fb_get_fields($pdo, $formId, false));
    $ctx = fb_public_ctx($pdo);
    $self = fb_safe_return_url((string)($_SERVER['REQUEST_URI'] ?? '/'));

    // Flash scoped to this form (multiple forms may share a page)
    $flash = (($_GET['fb_form'] ?? '') === $slug) ? (string)($_GET['fb_status'] ?? '') : '';
    $ref = preg_replace('/[^A-Z0-9\-]/i', '', (string)($_GET['fb_ref'] ?? ''));
    $msg = trim((string)($_GET['fb_msg'] ?? ''));

    // Price map for JS live total
    $priceMap = [];
    foreach ($allFields as $f) {
        if (!in_array($f['type'], ['select', 'radio', 'checkbox'], true)) continue;
        foreach (fb_field_options($f) as $o) {
            if ($o['price'] !== 0) $priceMap[$f['field_key'] . '::' . $o['value']] = $o['price'];
        }
    }
    $showTotal = $settings['show_total'] === '1' && $priceMap !== [];

    static $cssPrinted = false;
    ob_start();

    if (!$cssPrinted):
        $cssPrinted = true; ?>
<style>
.fb-wrap, .fb-wrap * { box-sizing: border-box; margin: 0; padding: 0; }
.fb-wrap {
  --fb-accent: #2b7a4a; --fb-accent-deep: #1c5633; --fb-danger: #be2d2d;
  --fb-surface: #ffffff; --fb-bg: #f4f7f2; --fb-text: #1d241d; --fb-muted: #6b776b;
  --fb-border: #d9e2d6; --fb-radius: 14px;
  font-family: inherit; color: var(--fb-text); line-height: 1.55;
  background: var(--fb-bg); border: 1px solid var(--fb-border);
  border-radius: 20px; padding: clamp(1.4rem, 3.5vw, 2.6rem); margin: 1.5rem 0;
}
.fb-title { font-size: 1.35rem; font-weight: 700; margin-bottom: .35rem; }
.fb-desc { color: var(--fb-muted); font-size: .94rem; margin-bottom: 1.4rem; }
.fb-rows { display: flex; flex-direction: column; gap: 1rem; }
.fb-row { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1rem 1.1rem; }
.fb-col { grid-column: span 12; display: flex; flex-direction: column; gap: 1rem; }
.fb-col.c6 { grid-column: span 6; } .fb-col.c4 { grid-column: span 4; } .fb-col.c3 { grid-column: span 3; }
@media (max-width: 640px) { .fb-col { grid-column: span 12 !important; } }
.fb-field label.fb-label { display: block; font-size: .74rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; margin-bottom: .42rem; color: var(--fb-text); }
.fb-label .req { color: var(--fb-danger); }
.fb-field input[type=text], .fb-field input[type=email], .fb-field input[type=tel], .fb-field input[type=number], .fb-field input[type=date], .fb-field textarea, .fb-field select {
  width: 100%; font: inherit; font-size: .95rem; color: var(--fb-text);
  background: var(--fb-surface); border: 1.5px solid var(--fb-border); border-radius: var(--fb-radius);
  padding: .7rem .95rem; outline: none; transition: border-color .25s, box-shadow .25s;
}
.fb-field textarea { min-height: 110px; resize: vertical; }
.fb-field select { cursor: pointer; }
.fb-field input:focus, .fb-field textarea:focus, .fb-field select:focus { border-color: var(--fb-accent); box-shadow: 0 0 0 4px rgba(43 122 74 / .14); }
.fb-help { font-size: .76rem; color: var(--fb-muted); margin-top: .35rem; }
.fb-choices { display: flex; flex-direction: column; gap: .5rem; }
.fb-choice { display: flex; align-items: center; gap: .55rem; font-size: .94rem; background: var(--fb-surface); border: 1.5px solid var(--fb-border); border-radius: var(--fb-radius); padding: .6rem .9rem; cursor: pointer; transition: border-color .2s, background .2s; }
.fb-choice:hover { border-color: var(--fb-accent); }
.fb-choice input { accent-color: var(--fb-accent); width: 16px; height: 16px; flex-shrink: 0; }
.fb-choice .price { margin-left: auto; font-size: .76rem; font-weight: 700; color: var(--fb-accent-deep); white-space: nowrap; }
.fb-heading { font-weight: 700; padding-bottom: .4rem; border-bottom: 2px solid var(--fb-border); margin: 0; }
.fb-h1 { font-size: 1.9rem; } .fb-h2 { font-size: 1.5rem; } .fb-h3 { font-size: 1.25rem; }
.fb-h4 { font-size: 1.12rem; } .fb-h5 { font-size: 1rem; } .fb-h6 { font-size: .9rem; text-transform: uppercase; letter-spacing: .05em; }
.fb-paragraph { color: var(--fb-muted); font-size: .94rem; }
.fb-divider { border: none; border-top: 1.5px dashed var(--fb-border); margin: .4rem 0; }
.fb-richtext { font-size: .94rem; line-height: 1.6; }
.fb-richtext img { max-width: 100%; height: auto; border-radius: 8px; }
.fb-richtext figure { margin: .5rem 0; }
.fb-richtext figcaption, .fb-image figcaption { font-size: .8rem; color: var(--fb-muted); text-align: center; margin-top: .35rem; }
.fb-image { margin: 0; }
.fb-image img { max-width: 100%; height: auto; border-radius: 10px; display: block; }
.fb-rawhtml > *:first-child { margin-top: 0; }
.fb-drop { position: relative; border: 2px dashed var(--fb-border); border-radius: var(--fb-radius); padding: 1.5rem 1rem; text-align: center; background: var(--fb-surface); transition: border-color .25s, background .25s; cursor: pointer; }
.fb-drop:hover, .fb-drop.dragover { border-color: var(--fb-accent); background: rgba(43 122 74 / .05); }
.fb-drop input[type=file] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
.fb-drop .up-t, .fb-drop .up-s, .fb-drop .up-ic, .fb-drop .up-preview { position: relative; z-index: 1; pointer-events: none; }
.fb-drop .up-ic { font-size: 1.5rem; margin-bottom: .35rem; }
.fb-drop .up-t { font-weight: 600; font-size: .92rem; }
.fb-drop .up-s { font-size: .74rem; color: var(--fb-muted); margin-top: .2rem; }
.fb-drop .up-preview { display: none; margin: 0 auto .5rem; max-width: 160px; max-height: 120px; border-radius: 10px; object-fit: cover; box-shadow: 0 4px 14px rgba(0 0 0 / .12); }
.fb-drop.has-file { border-style: solid; border-color: var(--fb-accent); background: rgba(43 122 74 / .06); }
.fb-total { display: flex; justify-content: space-between; align-items: center; gap: 1rem; background: rgba(43 122 74 / .07); border: 1.5px dashed var(--fb-accent); border-radius: var(--fb-radius); padding: .9rem 1.2rem; margin-top: 1rem; }
.fb-total .lbl { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--fb-accent-deep); }
.fb-total .amt { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.fb-total .amt.pop { animation: fb-pop .45s cubic-bezier(.34,1.56,.64,1); }
.fb-submit { width: 100%; margin-top: 1rem; display: inline-flex; align-items: center; justify-content: center; gap: .5rem; border: none; cursor: pointer; font: inherit; font-size: 1rem; font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--fb-accent), var(--fb-accent-deep)); border-radius: var(--fb-radius); padding: .9rem 1.5rem; box-shadow: 0 8px 22px rgba(43 122 74 / .3); transition: transform .2s, box-shadow .2s; }
.fb-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(43 122 74 / .4); }
.fb-submit:disabled { opacity: .75; cursor: wait; transform: none; }
.fb-flash-err { background: rgba(190 45 45 / .08); border: 1.5px solid rgba(190 45 45 / .4); color: var(--fb-danger); border-radius: var(--fb-radius); padding: .8rem 1rem; font-size: .9rem; font-weight: 600; margin-bottom: 1rem; }
.fb-success { text-align: center; padding: 2.5rem 1rem; }
.fb-success .check { width: 72px; height: 72px; margin: 0 auto 1rem; border-radius: 50%; background: linear-gradient(135deg, var(--fb-accent), var(--fb-accent-deep)); color: #fff; display: grid; place-items: center; font-size: 2rem; animation: fb-pop .55s cubic-bezier(.34,1.56,.64,1); }
.fb-success h3 { font-size: 1.4rem; margin-bottom: .5rem; }
.fb-success p { color: var(--fb-muted); max-width: 44ch; margin: 0 auto 1.2rem; }
.fb-ref { display: inline-block; font-family: ui-monospace, monospace; font-weight: 700; letter-spacing: .08em; background: var(--fb-surface); border: 1.5px dashed var(--fb-accent); color: var(--fb-accent-deep); border-radius: 10px; padding: .5rem 1.1rem; }
@keyframes fb-pop { 0% { transform: scale(.7); } 60% { transform: scale(1.12); } 100% { transform: scale(1); } }
</style>
<?php endif; ?>

<?php if (!empty($form['css'])): ?>
<style>/* custom CSS for form <?= fb_h($slug) ?> */
<?= (string)$form['css'] ?>
</style>
<?php endif; ?>

<div class="fb-wrap" id="fb-<?= fb_h($slug) ?>">
<?php if ($flash === 'ok'): ?>
  <div class="fb-success">
    <div class="check">&#10003;</div>
    <h3><?= fb_h($form['title']) ?></h3>
    <p><?= nl2br(fb_h($settings['success_message'])) ?></p>
    <?php if ($ref !== ''): ?><span class="fb-ref"><?= fb_h($ref) ?></span><?php endif; ?>
  </div>
<?php else: ?>
  <div class="fb-title"><?= fb_h($form['title']) ?></div>
  <?php if (!empty($form['description'])): ?><div class="fb-desc"><?= nl2br(fb_h($form['description'])) ?></div><?php endif; ?>

  <form method="post" action="/form-submit/" enctype="multipart/form-data" data-fb-form="<?= fb_h($slug) ?>" novalidate>
    <input type="hidden" name="fb_form_id" value="<?= $formId ?>">
    <input type="hidden" name="fb_slug" value="<?= fb_h($slug) ?>">
    <input type="hidden" name="csrf_token" value="<?= fb_h($ctx['csrf']) ?>">
    <input type="hidden" name="fb_return" value="<?= fb_h($self) ?>">
    <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="fb_website" tabindex="-1" autocomplete="off"></div>

    <?php if ($flash === 'err'): ?>
    <div class="fb-flash-err"><?= fb_h($msg !== '' ? $msg : 'Submission failed. Please check your input.') ?></div>
    <?php endif; ?>

    <div class="fb-rows">
    <?php foreach ($tree as $row):
        $n = max(1, count($row['cols']));
        $span = intdiv(12, min(4, $n));
        $colCls = ['12' => '', '6' => ' c6', '4' => ' c4', '3' => ' c3'][$span] ?? ''; ?>
      <div class="fb-row">
        <?php foreach ($row['cols'] as $col): ?>
        <div class="fb-col<?= $colCls ?>">
          <?php foreach ($col['fields'] as $f) echo fb_render_field_html($f, $slug); ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <?php if ($showTotal): ?>
    <div class="fb-total">
      <span class="lbl"><?= fb_h($settings['total_label']) ?></span>
      <span class="amt" data-fb-total><?= fb_format_rupiah(0) ?></span>
    </div>
    <?php endif; ?>

    <button type="submit" class="fb-submit" data-fb-submit><?= fb_h($settings['submit_label']) ?></button>
  </form>
<?php endif; ?>
</div>

<script>
(function () {
  var root = document.getElementById('fb-<?= fb_h($slug) ?>');
  if (!root) return;
  var form = root.querySelector('form[data-fb-form]');
  if (!form) return;

  // ---- Live total ----
  var PRICES = <?= json_encode($priceMap, JSON_UNESCAPED_UNICODE) ?>;
  var totalEl = root.querySelector('[data-fb-total]');
  function rp(n) { return 'Rp ' + (n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function recalc() {
    if (!totalEl) return;
    var total = 0;
    form.querySelectorAll('input[name$="[]"]:checked, input[type=radio]:checked, select').forEach(function (el) {
      var name = el.name.replace(/\[\]$/, '');
      if (el.tagName === 'SELECT') {
        if (el.value) total += PRICES[name + '::' + el.value] || 0;
      } else {
        total += PRICES[name + '::' + el.value] || 0;
      }
    });
    var txt = rp(total);
    if (totalEl.textContent !== txt) {
      totalEl.textContent = txt;
      totalEl.classList.remove('pop'); void totalEl.offsetWidth; totalEl.classList.add('pop');
    }
  }
  form.addEventListener('change', recalc);
  recalc();

  // ---- Dropzones ----
  root.querySelectorAll('.fb-drop').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    var title = zone.querySelector('.up-t');
    var sub = zone.querySelector('.up-s');
    var preview = zone.querySelector('.up-preview');
    var max = parseInt(zone.getAttribute('data-max') || '5242880', 10);
    var isImage = zone.getAttribute('data-image') === '1';
    function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function setFile(file) {
      if (!file) return;
      if (isImage && file.type.indexOf('image/') !== 0) { title.textContent = 'Please choose an image file'; input.value = ''; zone.classList.remove('has-file'); return; }
      if (file.size > max) { title.textContent = 'File too large (' + fmt(file.size) + ')'; input.value = ''; zone.classList.remove('has-file'); return; }
      title.textContent = file.name;
      sub.textContent = fmt(file.size) + ' — ready to upload';
      zone.classList.add('has-file');
      if (preview) {
        try { preview.src = URL.createObjectURL(file); preview.style.display = 'block'; } catch (e) {}
      }
    }
    input.addEventListener('change', function () { setFile(input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('dragover'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('dragover'); }); });
    zone.addEventListener('drop', function (e) {
      var f = e.dataTransfer.files[0];
      if (f) { try { var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; } catch (err) {} setFile(f); }
    });
  });

  // ---- Submit loader ----
  var btn = form.querySelector('[data-fb-submit]');
  form.addEventListener('submit', function (e) {
    if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
  });
})();
</script>
<?php if (!empty($form['js'])): ?>
<script>/* custom JS for form <?= fb_h($slug) ?> */
(function (root, form) {
<?= (string)$form['js'] ?>
})(document.getElementById('fb-<?= fb_h($slug) ?>'), document.querySelector('#fb-<?= fb_h($slug) ?> form'));
</script>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}
