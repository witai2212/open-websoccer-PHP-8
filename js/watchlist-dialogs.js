/* CM23 Task 1003 | 2026-08-24 | Revision 1 */
$(function() {
    var ADD_ACTION = 'put-player-on-watchlist';
    var REMOVE_ACTION = 'remove-player-from-watchlist';
    var pendingAction = null;
    var modal = $('#watchlistActionModal');
    var confirmButton = $('#watchlistActionConfirm');
    var ajaxLoader = $('#ajaxLoaderPage');

    if (!modal.length) {
        return;
    }

    function parseQueryString(url) {
        var query = '';
        var result = {};
        var queryStart = url.indexOf('?');

        if (queryStart >= 0) {
            query = url.substring(queryStart + 1);
        } else if (url.charAt(0) === '?') {
            query = url.substring(1);
        } else if (url.indexOf('=') >= 0 && url.indexOf('/') < 0) {
            query = url;
        }

        if (!query.length) {
            return result;
        }

        query = query.split('#')[0];
        $.each(query.split('&'), function(index, part) {
            var pair;
            var key;
            var value;

            if (!part.length) {
                return;
            }

            pair = part.split('=');
            key = decodeURIComponent((pair.shift() || '').replace(/\+/g, ' '));
            value = decodeURIComponent((pair.join('=') || '').replace(/\+/g, ' '));

            if (key.length) {
                result[key] = value;
            }
        });

        return result;
    }

    function getFormAction(form) {
        var actionInput = form.find('input[name="action"]').first();
        var actionParams;

        if (actionInput.length && actionInput.val()) {
            return actionInput.val();
        }

        actionParams = parseQueryString(form.attr('action') || '');
        return actionParams.action || '';
    }

    function isWatchlistAction(action) {
        return action === ADD_ACTION || action === REMOVE_ACTION;
    }

    function getFormPayload(form, action) {
        var payload = form.serializeArray();
        var hasAction = false;

        $.each(payload, function(index, item) {
            if (item.name === 'action') {
                item.value = action;
                hasAction = true;
            }
        });

        if (!hasAction) {
            payload.push({name: 'action', value: action});
        }

        return payload;
    }

    function getLinkPayload(link, action) {
        var params = parseQueryString(link.attr('href') || '');
        var payload = [];

        params.action = action;
        $.each(params, function(key, value) {
            payload.push({name: key, value: value});
        });

        return payload;
    }

    function currentPageId() {
        return parseQueryString(window.location.search || '').page || '';
    }

    function setButtonContent(button, iconClass, label) {
        var icon = button.find('i').first();

        if (icon.length) {
            icon.attr('class', iconClass);
            button.contents().filter(function() {
                return this.nodeType === 3;
            }).remove();
            button.append(document.createTextNode(' ' + label));
        } else {
            button.text(label);
        }
    }

    function cleanActionFromUrl(url) {
        var parts;
        var base;
        var query;
        var kept = [];

        if (!url || url.indexOf('?') < 0) {
            return url;
        }

        parts = url.split('?');
        base = parts.shift();
        query = parts.join('?').split('#')[0];

        $.each(query.split('&'), function(index, part) {
            if (part.length && part.split('=')[0] !== 'action') {
                kept.push(part);
            }
        });

        return base + (kept.length ? '?' + kept.join('&') : '');
    }

    function toggleForm(form, completedAction) {
        var nextAction = completedAction === ADD_ACTION ? REMOVE_ACTION : ADD_ACTION;
        var actionInput = form.find('input[name="action"]').first();
        var button = form.find('button[type="submit"], input[type="submit"]').first();
        var nextLabel;
        var nextIcon;

        if (!actionInput.length) {
            actionInput = $('<input type="hidden" name="action" />').appendTo(form);
        }
        actionInput.val(nextAction);
        form.attr('action', cleanActionFromUrl(form.attr('action') || ''));

        if (nextAction === ADD_ACTION) {
            nextLabel = modal.data('add-label');
            nextIcon = 'icon-eye-open';
        } else {
            nextLabel = modal.data('remove-label');
            nextIcon = 'icon-trash';
        }

        if (button.is('button')) {
            setButtonContent(button, nextIcon, nextLabel);
        } else if (button.length) {
            button.val(nextLabel);
        }
    }

    function toggleLink(link, completedAction) {
        var nextAction = completedAction === ADD_ACTION ? REMOVE_ACTION : ADD_ACTION;
        var href = link.attr('href') || '';
        var nextLabel;
        var nextIcon;

        href = href.replace(completedAction, nextAction);
        link.attr('href', href);

        if (nextAction === ADD_ACTION) {
            nextLabel = modal.data('add-label');
            nextIcon = 'icon-eye-open';
        } else {
            nextLabel = modal.data('remove-label');
            nextIcon = 'icon-trash';
        }

        setButtonContent(link, nextIcon, nextLabel);
    }

    function removeWatchlistRow(source) {
        var row = source.closest('tr');
        var table;

        if (!row.length) {
            return false;
        }

        table = row.closest('table');
        row.remove();

        if (table.length && table.find('tbody tr').length === 0) {
            table.replaceWith($('<p></p>').text(modal.data('empty-label')));
        }

        return true;
    }

    function updateSource(source, sourceType, completedAction) {
        if (completedAction === REMOVE_ACTION && currentPageId() === 'mywatchlist') {
            if (removeWatchlistRow(source)) {
                return;
            }
        }

        if (sourceType === 'form') {
            toggleForm(source, completedAction);
        } else {
            toggleLink(source, completedAction);
        }
    }

    function hasErrorMessage(html) {
        var wrapper = $('<div></div>').html(html || '');
        return wrapper.find('.alert-error, .alert-danger').length > 0;
    }

    function showRequestError() {
        var message = modal.data('request-error');
        $('#messages').html(
            $('<div class="alert alert-block alert-error"></div>').append(
                $('<h4></h4>').text(modal.data('error-title')),
                document.createTextNode(message)
            )
        );
    }

    function openDialog(source, sourceType, action, payload) {
        var isAdd = action === ADD_ACTION;

        pendingAction = {
            source: source,
            sourceType: sourceType,
            action: action,
            payload: payload
        };

        $('#watchlistActionModalLabel').text(
            isAdd ? modal.data('add-title') : modal.data('remove-title')
        );
        $('#watchlistActionModalMessage').text(
            isAdd ? modal.data('add-message') : modal.data('remove-message')
        );
        confirmButton.text(
            isAdd ? modal.data('confirm-add') : modal.data('confirm-remove')
        );
        confirmButton.removeAttr('disabled');
        modal.modal('show');
    }

    $(document).on('submit', 'form', function(event) {
        var form = $(this);
        var action = getFormAction(form);

        if (!isWatchlistAction(action)) {
            return;
        }

        event.preventDefault();
        openDialog(form, 'form', action, getFormPayload(form, action));
    });

    $(document).on('click', 'a[href*="action=put-player-on-watchlist"], a[href*="action=remove-player-from-watchlist"]', function(event) {
        var link = $(this);
        var params = parseQueryString(link.attr('href') || '');
        var action = params.action || '';

        if (!isWatchlistAction(action)) {
            return;
        }

        event.preventDefault();
        openDialog(link, 'link', action, getLinkPayload(link, action));
    });

    confirmButton.on('click', function() {
        var request;

        if (!pendingAction) {
            return;
        }

        request = pendingAction;
        confirmButton.attr('disabled', 'disabled');
        ajaxLoader.show();

        $.ajax({
            url: 'ajax.php',
            type: 'post',
            data: request.payload,
            dataType: 'json'
        }).done(function(data) {
            var messages = data && data.messages ? data.messages : '';

            $('#messages').html(messages);

            if (!hasErrorMessage(messages)) {
                updateSource(request.source, request.sourceType, request.action);
            }
            modal.modal('hide');
        }).fail(function() {
            showRequestError();
            modal.modal('hide');
        }).always(function() {
            confirmButton.removeAttr('disabled');
            ajaxLoader.hide();
            pendingAction = null;
        });
    });

    modal.on('hidden', function() {
        if (!confirmButton.is(':disabled')) {
            pendingAction = null;
        }
    });
});
