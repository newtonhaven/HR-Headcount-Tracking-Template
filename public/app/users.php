<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access(ROLE_ADMIN);

page_start('Users', 'users', [
    'subtitle' => 'People who can sign in. Administrators can change data and manage users; viewers can read reports and analytics.',
    'actions' => '<button type="button" class="btn btn-primary" id="add-user"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Add user</button>',
]);
?>
<section class="panel" aria-label="User accounts">
    <div id="user-table"></div>
</section>
<?php
page_end(['/assets/js/users.js']);
