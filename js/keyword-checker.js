jQuery(document).ready(function($) {
    $('#keyword-check-now').on('click', function() {
        var data = {
            'action': 'keyword_checker_scan_ajax',
            'post_id': keywordChecker.postId,
            'nonce': keywordChecker.nonce
        };

        // Show a loading indicator (optional)
        $('#keyword-checker-results').html('<p>Checking keywords...</p>');

        // Send AJAX request to run the check
        $.post(keywordChecker.ajaxUrl, data, function(response) {
            if (response.success) {
                $('#keyword-checker-results').html(response.data);
            } else {
                $('#keyword-checker-results').html('<p style="color: red;">' + response.data + '</p>');
            }
        });
    });
});
