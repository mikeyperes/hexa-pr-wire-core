(function () {
    'use strict';

    var config = window.hprwcPostViewAs;
    var input = document.getElementById('hprwc-view-as-query');
    var results = document.getElementById('hprwc-view-as-results');
    var status = document.getElementById('hprwc-view-as-status');
    if (!config || !input || !results || !status) {
        return;
    }

    var timer;
    var sequence = 0;
    var pending;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        sequence += 1;
        var request = sequence;
        if (pending) {
            pending.abort();
        }
        results.replaceChildren();
        status.textContent = '';
        var query = input.value.trim();
        if (query.length < 2) {
            return;
        }

        timer = setTimeout(function () {
            pending = new AbortController();
            status.textContent = 'Searching…';
            var body = new URLSearchParams({
                action: config.action,
                nonce: config.nonce,
                post_id: config.postId,
                view: config.view,
                query: query
            });
            fetch(config.url, { method: 'POST', credentials: 'same-origin', body: body, signal: pending.signal })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Search unavailable');
                    }
                    return response.json();
                })
                .then(function (response) {
                    if (request !== sequence) {
                        return;
                    }
                    if (!response.success || !response.data || !Array.isArray(response.data.users)) {
                        throw new Error('Search unavailable');
                    }
                    response.data.users.forEach(function (user) {
                        var item = document.createElement('li');
                        var link = document.createElement('a');
                        link.textContent = 'View as ' + user.label;
                        link.href = user.url;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        item.appendChild(link);
                        results.appendChild(item);
                    });
                    status.textContent = response.data.users.length ? 'Select a user to open a new tab.' : 'No matching users.';
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError' && request === sequence) {
                        status.textContent = 'Could not search users. Try again.';
                    }
                });
        }, 250);
    });
}());
