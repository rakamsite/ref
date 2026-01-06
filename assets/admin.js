jQuery(function ($) {
  function request(action, data) {
    return $.post(RRBSettings.ajaxUrl, $.extend({
      action: action,
      nonce: RRBSettings.nonce
    }, data));
  }

  function showNotice(message, type) {
    var noticeArea = $('.rrb-notice-area');
    if (!noticeArea.length) {
      return;
    }
    noticeArea
      .removeClass('rrb-notice-success rrb-notice-error')
      .addClass(type === 'error' ? 'rrb-notice-error' : 'rrb-notice-success')
      .text(message);
  }

  function collectProductIds() {
    return $('.rrb-table tbody tr').map(function () {
      return $(this).data('product-id');
    }).get();
  }

  function pollStatuses() {
    request('rrb_poll_status', { product_ids: collectProductIds() })
      .done(function (response) {
        if (!response.success) {
          return;
        }
        $.each(response.data, function (productId, payload) {
          var row = $('.rrb-table tbody tr[data-product-id="' + productId + '"]');
          row.find('.rrb-status-badge').text(payload.status_label)
            .removeClass('rrb-status-pending rrb-status-queued rrb-status-running rrb-status-done rrb-status-error')
            .addClass('rrb-status-' + payload.status);
          row.find('.rrb-error').text(payload.error || '');
          row.find('.rrb-result').html(payload.result_html || '');
        });
      });
  }

  setInterval(pollStatuses, 15000);

  $('.rrb-table').on('change', '.rrb-url-input', function () {
    var row = $(this).closest('tr');
    request('rrb_save_url', {
      product_id: row.data('product-id'),
      url: $(this).val()
    });
  });

  $('.rrb-table').on('click', '.rrb-queue', function () {
    var row = $(this).closest('tr');
    request('rrb_queue_item', {
      product_id: row.data('product-id'),
      url: row.find('.rrb-url-input').val()
    });
  });

  $('.rrb-table').on('click', '.rrb-run', function () {
    var row = $(this).closest('tr');
    request('rrb_queue_item', {
      product_id: row.data('product-id'),
      url: row.find('.rrb-url-input').val(),
      run_now: 1
    });
  });

  $('.rrb-table').on('click', '.rrb-force-refresh', function () {
    var row = $(this).closest('tr');
    request('rrb_force_refresh', {
      product_id: row.data('product-id'),
      url: row.find('.rrb-url-input').val()
    });
  });

  $('.rrb-table').on('click', '.rrb-undo', function () {
    var row = $(this).closest('tr');
    request('rrb_undo', {
      product_id: row.data('product-id')
    }).done(function (response) {
      if (!response.success) {
        alert(response.data);
      }
    });
  });

  $('#rrb-bulk-apply').on('click', function () {
    request('rrb_bulk_apply', {
      bulk: $('#rrb-bulk-input').val()
    }).done(function (response) {
      if (!response.success) {
        showNotice(response.data || 'خطا در ثبت اطلاعات.', 'error');
        return;
      }
      response.data.applied.forEach(function (item) {
        var row = $('.rrb-table tbody tr[data-product-id="' + item.product_id + '"]');
        row.find('.rrb-url-input').val(item.url);
      });
      showNotice('ورودی‌های گروهی اعمال و صف‌بندی شدند.', 'success');
    });
  });

  $('#rrb-start-queue').on('click', function () {
    request('rrb_toggle_queue', { queue_action: 'start' }).done(function (response) {
      if (!response.success) {
        showNotice(response.data || 'خطا در شروع صف.', 'error');
        return;
      }
      $('.rrb-status').text('وضعیت صف: فعال');
      showNotice('پردازش صف شروع شد.', 'success');
    });
  });

  $('#rrb-pause-queue').on('click', function () {
    request('rrb_toggle_queue', { queue_action: 'pause' }).done(function (response) {
      if (!response.success) {
        showNotice(response.data || 'خطا در توقف صف.', 'error');
        return;
      }
      $('.rrb-status').text('وضعیت صف: متوقف');
      showNotice('صف متوقف شد.', 'success');
    });
  });

  $('#rrb-resume-queue').on('click', function () {
    request('rrb_toggle_queue', { queue_action: 'resume' }).done(function (response) {
      if (!response.success) {
        showNotice(response.data || 'خطا در ادامه صف.', 'error');
        return;
      }
      $('.rrb-status').text('وضعیت صف: فعال');
      showNotice('صف دوباره فعال شد.', 'success');
    });
  });
});
