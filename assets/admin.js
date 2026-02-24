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

  $('#rrb-start-queue').on('click', function () {
    var items = $('.rrb-table tbody tr').map(function () {
      var row = $(this);
      var url = (row.find('.rrb-url-input').val() || '').trim();
      if (!url) {
        return null;
      }
      return {
        product_id: row.data('product-id'),
        url: url
      };
    }).get();

    if (!items.length) {
      showNotice('حداقل یک لینک بهران وارد کنید.', 'error');
      return;
    }

    request('rrb_queue_multiple', { items: items }).done(function (response) {
      if (!response.success) {
        showNotice(response.data || 'خطا در ثبت و شروع پردازش.', 'error');
        return;
      }
      $('.rrb-status').text('وضعیت صف: فعال');
      showNotice(response.data.queued + ' محصول صف‌بندی شد و ساخت تگ‌ها شروع شد.', 'success');
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
