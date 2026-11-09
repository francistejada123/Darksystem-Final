<?php
// parent_login.php -- easy-to-remember address of the Parent Portal login.
// There is no separate Parent login page any more: parents sign in from the
// Student Portal login page, on the Parent tab (child's LRN + guardian name).
header('Location: student_login.php?as=parent');
