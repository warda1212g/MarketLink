<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/home_layout.php';
require_once APP_ROOT . '/includes/services/contact.php';

$user = current_user();
$old = ['name' => $user['name'] ?? '', 'email' => $user['email'] ?? '', 'topic' => 'general', 'message' => ''];
$topics = CONTACT_TOPICS;
$errors = [];

if (is_post()) {
    verify_csrf_or_fail();
    $old = [
        'name' => str_input($_POST, 'name', 120),
        'email' => mb_strtolower(str_input($_POST, 'email', 190)),
        'topic' => (string) ($_POST['topic'] ?? 'general'),
        'message' => str_input($_POST, 'message', 2000),
    ];
    if (mb_strlen($old['name']) < 2) $errors['name'] = 'Please enter your name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!isset($topics[$old['topic']])) $old['topic'] = 'general';
    if (mb_strlen($old['message']) < 10) $errors['message'] = 'Tell us a little more (at least 10 characters).';

    if (!$errors) {
        contact_ensure_table();
        db_query('INSERT INTO contact_messages (user_id, name, email, topic, message) VALUES (?, ?, ?, ?, ?)',
            [$user['id'] ?? null, $old['name'], $old['email'], $old['topic'], $old['message']]);
        flash('success', 'Thanks, ' . $old['name'] . '! Your message has been sent - we usually reply within one working day.');
        redirect('contact.php#write');
    }
}

// photos used on this page
$photo = fn(string $p) => e(url($p));

home_layout_start([
    'title' => 'Contact Us',
    'active' => 'contact',
    'description' => 'Get in touch with the MarketLink team.',
    'styles' => ['assets/css/contact.css'],
    'hero' => [
        'eyebrow' => 'GET IN TOUCH',
        'lines' => ['LET\'S', 'TALK', 'FRESH.'],
        'copy' => 'Questions about an order, joining as a farmer, or bringing MarketLink to your market? We would love to hear from you.',
        'image' => 'https://images.unsplash.com/photo-1498579397066-22750a3cb424?q=80&w=1800&auto=format&fit=crop',
    ],
]);
?>

<!-- Channels -->
<section class="ct-channels on-cream" aria-label="Ways to reach us">
  <div class="container ct-channel-grid">
    <a class="ct-channel reveal-on-scroll" href="mailto:hello@marketlink.local">
      <span class="ct-channel-icon"><svg viewBox="0 0 24 24"><use href="#icon-mail"/></svg></span>
      <h3>Email us</h3>
      <p>hello@marketlink.local</p>
      <span class="ct-channel-link">Write an email <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></span>
    </a>
    <div class="ct-channel reveal-on-scroll" style="--stagger:.06s">
      <span class="ct-channel-icon"><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg></span>
      <h3>Support hours</h3>
      <p>Mon - Sat, 9:00 AM - 6:00 PM</p>
      <span class="ct-channel-note">Replies within one working day</span>
    </div>
    <div class="ct-channel reveal-on-scroll" style="--stagger:.12s">
      <span class="ct-channel-icon"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg></span>
      <h3>Head office</h3>
      <p>Karachi, Sindh, Pakistan</p>
      <span class="ct-channel-note">Serving markets nationwide</span>
    </div>
    <a class="ct-channel reveal-on-scroll" style="--stagger:.18s" href="<?= e(url('auth/register.php?role=vendor')) ?>">
      <span class="ct-channel-icon"><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg></span>
      <h3>For farmers</h3>
      <p>Open your stall on MarketLink</p>
      <span class="ct-channel-link">Join as a farmer <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></span>
    </a>
  </div>
</section>

<!-- Form -->
<section class="ct-section on-cream" id="write">
  <div class="container ct-form-wrap">
    <aside class="ct-aside reveal-on-scroll" style="background-image:url('<?= $photo('uploads/markets/wiki-ab2fa920ce96fe14.jpg') ?>')">
      <div class="ct-aside-inner">
        <p class="kicker">Send a message</p>
        <h2 class="h-serif">We're here<br>to <em>help</em>.</h2>
        <p class="ct-aside-copy">Tell us what you need and our team will reply by email. For a pre-order question, include your order number so we can loop in the farmer.</p>
        <ul class="ct-promise">
          <li><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg><div><strong>Fast replies</strong><span>Usually within one working day</span></div></li>
          <li><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg><div><strong>Farmer in the loop</strong><span>Order issues are shared with the stall</span></div></li>
          <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg><div><strong>Private by default</strong><span>Your details are never shared publicly</span></div></li>
        </ul>
      </div>
    </aside>

    <form class="ct-form reveal-on-scroll" style="--stagger:.1s" method="post" action="<?= e(url('contact.php')) ?>#write" novalidate>
      <?= csrf_field() ?>
      <div class="ct-form-head">
        <h3>Send us a message</h3>
        <p>All fields are required.</p>
      </div>
      <?php foreach (take_flashes() as $f): ?>
        <div class="hp-alert hp-alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <div class="ct-row">
        <label class="ct-field<?= isset($errors['name']) ? ' has-error' : '' ?>">
          <span>Your name</span>
          <input type="text" name="name" maxlength="120" placeholder="e.g. Ayesha Khan" value="<?= e($old['name']) ?>" required<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
          <?php if (isset($errors['name'])): ?><small><?= e($errors['name']) ?></small><?php endif; ?>
        </label>
        <label class="ct-field<?= isset($errors['email']) ? ' has-error' : '' ?>">
          <span>Email address</span>
          <input type="email" name="email" maxlength="190" placeholder="you@example.com" value="<?= e($old['email']) ?>" required<?= isset($errors['email']) ? ' aria-invalid="true"' : '' ?>>
          <?php if (isset($errors['email'])): ?><small><?= e($errors['email']) ?></small><?php endif; ?>
        </label>
      </div>
      <fieldset class="ct-field">
        <legend>What's it about?</legend>
        <div class="ct-topics">
          <?php foreach ($topics as $k => $label): ?>
            <label><input type="radio" name="topic" value="<?= e($k) ?>"<?= $old['topic'] === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <label class="ct-field<?= isset($errors['message']) ? ' has-error' : '' ?>">
        <span>Message</span>
        <textarea name="message" rows="6" maxlength="2000" placeholder="How can we help?" required<?= isset($errors['message']) ? ' aria-invalid="true"' : '' ?>><?= e($old['message']) ?></textarea>
        <?php if (isset($errors['message'])): ?><small><?= e($errors['message']) ?></small><?php endif; ?>
      </label>
      <div class="ct-form-foot">
        <p><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> We'll only use your email to reply.</p>
        <button type="submit" class="ct-submit">Send message <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></button>
      </div>
    </form>
  </div>
</section>

<!-- Help paths -->
<section class="ct-section on-white" id="help">
  <div class="container">
    <header class="ct-head reveal-on-scroll">
      <p class="kicker">Find the right help</p>
      <h2 class="h-serif">Get answers <em>faster</em>.</h2>
    </header>
    <div class="ct-paths">
      <a class="ct-path reveal-on-scroll" href="<?= e(url('customer/orders.php')) ?>">
        <span class="ct-path-img" style="background-image:url('<?= $photo('uploads/markets/wiki-a9b3b0bab6b4f4a6.jpg') ?>')"></span>
        <span class="ct-path-body"><small>Customers</small><strong>Track or cancel an order</strong><em>Go to My Orders <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></em></span>
      </a>
      <a class="ct-path reveal-on-scroll" style="--stagger:.08s" href="<?= e(url('vendor/dashboard.php')) ?>">
        <span class="ct-path-img" style="background-image:url('<?= $photo('uploads/markets/wiki-d0be0b9895679825.jpg') ?>')"></span>
        <span class="ct-path-body"><small>Farmers &amp; vendors</small><strong>Manage your stall &amp; stock</strong><em>Open vendor dashboard <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></em></span>
      </a>
      <a class="ct-path reveal-on-scroll" style="--stagger:.16s" href="<?= e(url('markets.php')) ?>">
        <span class="ct-path-img" style="background-image:url('<?= $photo('uploads/markets/wiki-dc00b528b4661d32.jpg') ?>')"></span>
        <span class="ct-path-body"><small>Shoppers</small><strong>Find a market near you</strong><em>Browse markets <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></em></span>
      </a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="ct-section on-beige" id="faq">
  <div class="container ct-faq">
    <div class="ct-faq-intro reveal-on-scroll">
      <p class="kicker">Quick answers</p>
      <h2 class="h-serif">Frequently asked<br><em>questions</em>.</h2>
      <p class="lede">Can't find what you're looking for? Send us a message and we'll get back to you.</p>
      <a href="#write" class="ct-submit ct-submit-outline">Ask a question <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
    </div>
    <div class="ct-faq-list reveal-on-scroll" style="--stagger:.1s">
      <details open><summary>How do I pay for a pre-order?</summary><p>You pay the farmer in person when you collect your order at the stall. MarketLink never takes card payments online.</p></details>
      <details><summary>Can I cancel or change my order?</summary><p>Yes - you can cancel from My Orders any time before the farmer's cut-off for that market day.</p></details>
      <details><summary>What does "orders are currently closed" mean?</summary><p>That day's cut-off has passed, so the stall is preparing its orders. You can still pre-order for the next pickup day shown on the stall page.</p></details>
      <details><summary>How do I join as a farmer?</summary><p>Sign up and choose "Vendor". Once an admin approves your account, you can set up your stall, location and weekly stock.</p></details>
      <details><summary>Why is my account pending?</summary><p>Every new account is reviewed by our team to keep the marketplace trustworthy. This usually takes less than a day.</p></details>
    </div>
  </div>
</section>

<?php home_layout_end();
