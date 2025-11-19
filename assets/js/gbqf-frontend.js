(function () {
    function initGBQFFilters() {
        var filterBlocks = document.querySelectorAll('.gbqf-query-filter-block');

        if (!filterBlocks.length) {
            return;
        }

        filterBlocks.forEach(function (filterEl) {
            var targetId = filterEl.getAttribute('data-gbqf-target-id') || '';
            var autoApplyFlag = filterEl.getAttribute('data-gbqf-auto-apply');
            var autoApply = autoApplyFlag === '1';
            var ajaxEnabled = filterEl.getAttribute('data-gbqf-enable-ajax') !== '0';

            var targetQueryEl = null;
            var ajaxSubmit = null;
            var resetLink = null;

            if (targetId) {
                var selector = '#' + targetId;
                targetQueryEl = document.querySelector(selector);

                if (targetQueryEl) {
                    console.log('GBQF: Linked filter block → Query Loop:', {
                        filterBlock: filterEl,
                        targetId: targetId,
                        selector: selector,
                        queryElement: targetQueryEl,
                        autoApply: autoApply
                    });
                } else {
                    console.warn(
                        'GBQF: Target ID set but no matching element found on the page.',
                        { filterBlock: filterEl, targetId: targetId }
                    );
                }
            } else {
                console.warn(
                    'GBQF: No target ID set for this filter block. Filtering will still work via URL, but Query Loop is not explicitly linked.',
                    { filterBlock: filterEl }
                );
            }

            // IMPORTANT: Do NOT bail out if targetQueryEl is missing.
            // Auto-apply and Enter-to-submit should still work because they
            // only submit the form and rely on PHP/pre_get_posts + URL params.
            var form = filterEl.querySelector('form.gbqf-filter-form');
            if (form) {
                resetLink = form.querySelector('.gbqf-filter-reset');
            }

            var toggleResetVisibility = function () {
                if (!resetLink) {
                    return;
                }
                var formData = new FormData(form);
                var hasValue = false;
                formData.forEach(function (value) {
                    if (value !== null && value !== undefined && String(value).trim() !== '') {
                        hasValue = true;
                    }
                });
                if (hasValue) {
                    resetLink.classList.remove('is-hidden');
                } else {
                    resetLink.classList.add('is-hidden');
                }
            };

            // If we have a target element and fetch support, use AJAX to swap results.
            if (ajaxEnabled && form && targetQueryEl && window.fetch && window.DOMParser) {
                ajaxSubmit = function (event) {
                    if (event) {
                        event.preventDefault();
                    }

                    var formData = new FormData(form);
                    var params = new URLSearchParams();
                    formData.forEach(function (value, key) {
                        params.append(key, value);
                    });

                    var requestUrl = new URL(window.location.href);
                    requestUrl.search = params.toString();

                    if (targetQueryEl) {
                        targetQueryEl.classList.add('gbqf-loading');
                    }

                    fetch(requestUrl.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (resp) {
                            return resp.text();
                        })
                        .then(function (html) {
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');
                            var fresh = doc.querySelector('#' + targetId);

                            if (fresh && targetQueryEl) {
                                targetQueryEl.innerHTML = fresh.innerHTML;
                            }

                            if (window.history && window.history.replaceState) {
                                window.history.replaceState({}, '', requestUrl.toString());
                            }

                            toggleResetVisibility();
                        })
                        .catch(function () {
                            form.submit();
                        })
                        .finally(function () {
                            if (targetQueryEl) {
                                targetQueryEl.classList.remove('gbqf-loading');
                            }
                        });
                };

                form.addEventListener('submit', ajaxSubmit);
            }

            if (autoApply) {
                if (!form) {
                    return;
                }

                var submitForm = function () {
                    if (ajaxSubmit) {
                        ajaxSubmit();
                        return;
                    }
                    form.submit();
                };

                // Auto-apply for checkboxes / radios.
                var toggles = form.querySelectorAll('input[type="checkbox"], input[type="radio"]');
                toggles.forEach(function (input) {
                    input.addEventListener('change', submitForm);
                    input.addEventListener('change', toggleResetVisibility);
                });

                // Auto-apply for selects (e.g. Meta Box select field).
                var selects = form.querySelectorAll('select');
                selects.forEach(function (select) {
                    select.addEventListener('change', submitForm);
                    select.addEventListener('change', toggleResetVisibility);
                });

                // Text inputs (search, Meta Box text fallback, etc.): submit on Enter.
                var textInputs = form.querySelectorAll(
                    'input[type="text"], input[type="search"], input[type="email"], input[type="number"], input[type="url"]'
                );

                textInputs.forEach(function (input) {
                    input.addEventListener('keydown', function (event) {
                        var key = event.key || event.keyCode;
                        if (key === 'Enter' || key === 'Return' || key === 13) {
                            event.preventDefault();
                            submitForm();
                        }
                    });
                    input.addEventListener('input', toggleResetVisibility);
                });

                // NOTE: no auto-submit on every keystroke; only on change/Enter.
            }

            if (form) {
                toggleResetVisibility();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGBQFFilters);
    } else {
        initGBQFFilters();
    }
})();
