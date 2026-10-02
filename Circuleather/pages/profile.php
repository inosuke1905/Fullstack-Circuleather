<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$profileName = $_SESSION['full_name'] ?? '';
$profileEmail = $_SESSION['email'] ?? '';
$profileRole = ($_SESSION['role'] ?? 'worker') === 'admin'
    ? t('Beheerder', 'Administrator')
    : t('Medewerker', 'Worker');
?>
<section class="page-section profile-page" aria-labelledby="profile-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Account', 'Account') ?></p><h1 id="profile-title"><?= t('Profiel', 'Profile') ?></h1></div>
    </div>

    <section class="settings-panel account-profile-panel" aria-labelledby="account-details-title">
        <div class="settings-panel-heading">
            <span class="settings-icon" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($profileName !== '' ? $profileName : 'U', 0, 1))) ?></span>
            <div>
                <h2 id="account-details-title"><?= t('Accountgegevens', 'Account details') ?></h2>
                <p><?= t('Informatie van je account.', 'Information for your account.') ?></p>
            </div>
        </div>

        <dl class="account-profile-information">
            <div>
                <dt><?= t('Naam', 'Name') ?></dt>
                <dd><?= escape($profileName) ?></dd>
            </div>
            <div>
                <dt><?= t('E-mailadres', 'Email address') ?></dt>
                <dd><?= escape($profileEmail) ?></dd>
            </div>
            <div>
                <dt><?= t('Rol', 'Role') ?></dt>
                <dd><?= $profileRole ?></dd>
            </div>
        </dl>
    </section>
</section>