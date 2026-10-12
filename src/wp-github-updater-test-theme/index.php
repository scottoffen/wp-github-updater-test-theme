<?php
// A minimal classic template, so WordPress accepts the theme.
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<h1><?php bloginfo('name'); ?></h1>
<?php wp_footer(); ?>
</body>
</html>
