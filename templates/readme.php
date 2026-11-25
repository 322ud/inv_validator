<?php
script('inv_validator', 'readme');
style('inv_validator', 'admin');
style('inv_validator', 'readme-page');
$title = ['admin' => 'Administrationsanleitung', 'user' => 'Benutzeranleitung', 'developer' => 'Entwicklerdokumentation'][$_['document'] ?? 'readme'] ?? 'README';
?>
<div class="kosit-readme-page" data-document-title="<?php p($title); ?>">
    <?php require __DIR__ . '/readme-content.php'; ?>
</div>
