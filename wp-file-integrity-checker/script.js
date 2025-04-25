jQuery(document).ready(function($) {
    // Start scan when button is clicked
    $('#scan-files').on('click', function(e) {
        e.preventDefault();
        $('#scan-results').empty();
        $('#scan-progress').show();
        $('#progress-bar').val(0);
        $('#progress-text').text('0%');
        $(this).prop('disabled', true);

        $.ajax({
            url: wpFileIntegrity.ajax_url,
            method: 'POST',
            data: {
                action: 'wp_file_integrity_scan',
                nonce: wpFileIntegrity.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#scan-results').html(response.data.html);
                    $('#scan-progress').hide();
                    $('#scan-files').prop('disabled', false);
                    alert('Сканирование завершено!');
                } else {
                    $('#scan-results').html('<div class="notice notice-error"><p>Ошибка: ' + $('<div/>').text(response.data).html() + '</p></div>');
                    $('#scan-progress').hide();
                    $('#scan-files').prop('disabled', false);
                }
            },
            error: function() {
                $('#scan-results').html('<div class="notice notice-error"><p>Ошибка AJAX-запроса.</p></div>');
                $('#scan-progress').hide();
                $('#scan-files').prop('disabled', false);
            }
        });

        // Poll progress every second
        var progressInterval = setInterval(function() {
            $.ajax({
                url: wpFileIntegrity.ajax_url,
                method: 'POST',
                data: {
                    action: 'wp_file_integrity_progress',
                    nonce: wpFileIntegrity.nonce
                },
                success: function(response) {
                    if (response.success && response.data.progress) {
                        $('#progress-bar').val(response.data.progress);
                        $('#progress-text').text(Math.round(response.data.progress) + '%');
                        if (response.data.progress >= 100) {
                            clearInterval(progressInterval);
                        }
                    }
                }
            });
        }, 1000);
    });

    // Show more files when "Показать все" is clicked
    $(document).on('click', '.show-more', function(e) {
        e.preventDefault();
        var type = $(this).data('type');
        var $list = $(this).closest('.file-list');
        $list.find('li.hidden').removeClass('hidden');
        $(this).remove();
    });
});