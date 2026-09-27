<?php /** Admin layout - closing half. Pages may set $pageScripts (array of script URLs). */ ?>
  </main>
  <footer class="adm-footer">&copy; <?= date('Y') ?> MarketLink. Admin console.</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/admin.js"></script>
<script src="<?= e(asset('assets/js/notifications.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
