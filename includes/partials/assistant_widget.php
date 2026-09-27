<?php
/**
 * Floating chat assistant. Talks to api/marketlink_ai.php.
 */
declare(strict_types=1);

function assistant_widget(): void
{
    $user = current_user();
    if ($user && $user['role'] === 'admin') {
        return;
    }
    $suggestions = $user && $user['role'] === 'vendor'
        ? ['What is the status of my orders?', 'Which of my products are sold out?', 'What do customers say in my reviews?', 'How do pickup windows work?']
        : ['Which markets have the most products?', 'Who sells fresh vegetables?', 'Show highly rated Farmers.', 'How do I place an order?'];
    ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/assistant.css')) ?>">
<div class="mla" id="mlAssistant" data-endpoint="<?= e(url('api/marketlink_ai.php')) ?>" data-base="<?= e(url('')) ?>">
  <button type="button" class="mla-fab" data-mla-toggle aria-controls="mlaPanel" aria-expanded="false" aria-label="Open MarketLink AI Assistant">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c4.97 0 9 3.58 9 8s-4.03 8-9 8c-1.06 0-2.08-.16-3.02-.46L4 20l1.3-3.9A7.6 7.6 0 0 1 3 11c0-4.42 4.03-8 9-8Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
    <span>Ask MarketLink AI</span>
  </button>
  <section class="mla-panel" id="mlaPanel" role="dialog" aria-label="MarketLink AI Assistant" hidden>
    <header class="mla-head">
      <div>
        <strong>MarketLink AI</strong>
        <small>Ask me about markets, Farmers, products and your MarketLink experience.</small>
      </div>
      <button type="button" class="mla-icon" data-mla-clear title="New chat" aria-label="Start a new chat"><svg><use href="#i-refresh"/></svg></button>
      <button type="button" class="mla-icon" data-mla-toggle aria-label="Close assistant"><svg><use href="#i-close"/></svg></button>
    </header>
    <div class="mla-log" data-mla-log aria-live="polite">
      <div class="mla-suggest" data-mla-suggest>
        <?php foreach ($suggestions as $s): ?><button type="button" data-mla-ask><?= e($s) ?></button><?php endforeach; ?>
      </div>
    </div>
    <form class="mla-form" data-mla-form>
      <input type="text" name="message" maxlength="500" autocomplete="off" placeholder="Ask about markets, Farmers, products..." aria-label="Your question" required>
      <button type="submit" aria-label="Send"><svg><use href="#i-send"/></svg></button>
    </form>
    <p class="mla-foot">Answers use live MarketLink data only. AI can make mistakes - check the stall page before you go.</p>
  </section>
</div>
<script src="<?= e(asset('assets/js/assistant.js')) ?>" defer></script>
<?php
}
