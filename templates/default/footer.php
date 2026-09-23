</main>
        <hr style="border:0;border-top:1px solid var(--border-color);margin-top:30px;margin-bottom:15px;">
        <footer style="text-align:center;color:var(--text-color);opacity:.7;font-size:.9em;">

<?php
$counterFile = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/config/counter.json';
$todayVisits = 0;
$allTimeVisits = 0;

if (file_exists($counterFile)) {
    $counterData = json_decode((string)@file_get_contents($counterFile), true);
    $todayVisits = (int)($counterData['total'] ?? 0);
    $allTimeVisits = (int)($counterData['all_time_total'] ?? $todayVisits);
}
?>
            <p style="margin: 0 0 4px 0;">Dnes: <strong><?= number_format($todayVisits, 0, '', ' ') ?></strong></p>
            <p style="margin: 0 0 10px 0;">Celkem: <strong><?= number_format($allTimeVisits, 0, '', ' ') ?></strong></p>
            <p style="margin: 0;">&copy; <?= date('Y') ?> ScriptCMS ™</p>
        </footer>
    </div>

<script>
(function () {
    const button = document.getElementById('mobileMenuButton');
    const menu = document.getElementById('mobileNav');

    if (!button || !menu) return;

    button.addEventListener('click', function () {
        const open = menu.classList.toggle('active');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'Zavřít menu' : 'Otevřít menu');
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            menu.classList.remove('active');
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-label', 'Otevřít menu');
        }
    });
})();
</script>
</body>
</html>

