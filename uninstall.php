<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('ofwn_requesters');
delete_option('ofwn_workers');
