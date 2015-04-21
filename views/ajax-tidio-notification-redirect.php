<!doctype html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Redirect to Tidio Notification...</title>
    </head>
    <body>
    	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.2/jquery.min.js"></script>
        <?php /* ACCESS REDIRECT */ ?>

        <?php if ($view['mode'] == 'redirect') { ?>
            <script> location.href = '<?php echo $view['redirect_url'] ?>';</script>
        <?php } ?>

        <?php /* ACCESS REQUEST */ ?>
        <?php if ($view['mode'] == 'access_request') { ?>

            <img src="<?php echo plugins_url('media/img/ajax-loader.gif', dirname(__FILE__)); ?>" />
            <script>
                var path = {
                    admin_url: '<?php echo admin_url() ?>'
                };
                jQuery.getJSON('<?php echo $view['access_url'] ?>&remote=1', function (data) {
                    if (!data || !data['status']) {
                        alert('Error occurred, please refresh page and try again!');
                        return false;
                    }
                    data = data['value'];
                    location.href = path.admin_url + 'admin-ajax.php?action=tidio_notification_redirect&access_status=success&private_key=' + data.private_key + '&public_key=' + data.public_key;
                });
            </script>
        <?php } ?>
    </body>
</html>