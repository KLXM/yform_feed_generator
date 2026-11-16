/* Feed-Generator: Felder per AJAX nachladen, Filterzeilen verwalten */
(function () {
    'use strict';

    function init(form) {
        if (!form || form.dataset.yfgReady) {
            return;
        }
        form.dataset.yfgReady = '1';

        var api = form.dataset.api;
        var tableSelect = form.querySelector('#yfg-table');
        var status = form.querySelector('.yfg-loading');
        var tbody = form.querySelector('.yfg-filters tbody');
        var template = form.querySelector('#yfg-filter-template');
        var fields = null;
        var firstLoad = true;
        var counter = tbody.querySelectorAll('tr').length;

        function request(params) {
            var url = new URL(api, window.location.href);
            Object.keys(params).forEach(function (k) {
                url.searchParams.set(k, params[k]);
            });
            return fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) {
                    return r.json().then(function (data) {
                        if (!r.ok || data.error) {
                            throw new Error(data.error || ('HTTP ' + r.status));
                        }
                        return data;
                    });
                });
        }

        function fieldByName(name) {
            if (!fields) {
                return null;
            }
            for (var i = 0; i < fields.length; i++) {
                if (fields[i].name === name) {
                    return fields[i];
                }
            }
            return null;
        }

        function fillFieldSelect(select) {
            var current = select.value || select.dataset.selected || '';
            var emptyOption = select.querySelector('option[value=""]');
            var emptyLabel = emptyOption ? emptyOption.textContent : '';
            select.innerHTML = '';
            if (emptyOption) {
                select.appendChild(new Option(emptyLabel, ''));
            }
            fields.forEach(function (f) {
                var opt = new Option(f.label + ' [' + f.name + ', ' + f.type + ']', f.name);
                select.appendChild(opt);
            });
            if (fieldByName(current)) {
                select.value = current;
            }
            select.dataset.selected = select.value;
        }

        function loadFields(table) {
            if (!table) {
                return;
            }
            status.textContent = 'Felder werden geladen …';
            request({ action: 'fields', table: table })
                .then(function (data) {
                    fields = data.fields;
                    form.querySelectorAll('select.yfg-field').forEach(fillFieldSelect);
                    var profile = form.querySelector('#yfg-profile');
                    var currentProfile = profile.value;
                    profile.innerHTML = '';
                    profile.appendChild(new Option('– keins –', '0'));
                    Object.keys(data.profiles).forEach(function (id) {
                        profile.appendChild(new Option(data.profiles[id], id));
                    });
                    profile.value = data.profiles[currentProfile] ? currentProfile : '0';
                    // Werte der vorherigen Tabelle verwerfen und für die neue laden
                    tbody.querySelectorAll('tr').forEach(function (row) {
                        if (!firstLoad) {
                            row.querySelector('.yfg-checks').innerHTML = '';
                        }
                        updateFilterRow(row, !firstLoad);
                    });
                    firstLoad = false;
                    status.textContent = fields.length + ' Felder geladen.';
                })
                .catch(function (e) {
                    status.textContent = 'Felder konnten nicht geladen werden: ' + e.message;
                });
        }

        function updateFilterRow(row, reloadOptions) {
            var fieldSelect = row.querySelector('select.yfg-field');
            var op = row.querySelector('.yfg-op').value;
            var valueInput = row.querySelector('.yfg-value');
            var box = row.querySelector('.yfg-values');
            var checks = row.querySelector('.yfg-checks');
            var hint = row.querySelector('.yfg-hint');
            var info = fieldByName(fieldSelect.value);
            var isRelation = !!(info && info.relation);
            var needsValue = op !== 'empty' && op !== 'notempty';
            var useList = isRelation && ['any', 'all', 'none'].indexOf(op) > -1;

            hint.hidden = fieldSelect.value !== '';
            valueInput.hidden = !fieldSelect.value || !needsValue || useList;
            box.hidden = !needsValue || !useList;

            if (useList && (reloadOptions || checks.children.length === 0)) {
                if (!info.relation.supported) {
                    checks.innerHTML = '<p class="help-block">Diese Relationsart (1:n inline) wird für Filter nicht unterstützt.</p>';
                    return;
                }
                var selected = Array.prototype.map.call(checks.querySelectorAll('input:checked'), function (o) { return o.value; });
                var name = fieldSelect.name.replace(/\[field\]$/, '[values][]');
                checks.textContent = 'Werte werden geladen …';
                request({ action: 'options', table: tableSelect.value, field: info.name })
                    .then(function (data) {
                        checks.innerHTML = '';
                        data.options.forEach(function (o) {
                            var label = document.createElement('label');
                            label.className = 'yfg-check';
                            var input = document.createElement('input');
                            input.type = 'checkbox';
                            input.name = name;
                            input.value = String(o.id);
                            input.checked = selected.indexOf(String(o.id)) > -1;
                            label.appendChild(input);
                            label.appendChild(document.createTextNode(' ' + o.label));
                            checks.appendChild(label);
                        });
                        if (!data.options.length) {
                            checks.textContent = 'Keine Werte in der Zieltabelle.';
                        }
                        status.textContent = data.options.length + ' Werte für „' + info.label + '“ geladen.';
                    })
                    .catch(function (e) {
                        checks.textContent = 'Werte konnten nicht geladen werden: ' + e.message;
                    });
            }
        }

        tableSelect.addEventListener('change', function () {
            loadFields(tableSelect.value);
        });

        tbody.addEventListener('change', function (event) {
            var row = event.target.closest('.yfg-filter');
            if (!row) {
                return;
            }
            if (event.target.classList.contains('yfg-field')) {
                row.querySelector('.yfg-checks').innerHTML = '';
                updateFilterRow(row, true);
            } else if (event.target.classList.contains('yfg-op')) {
                updateFilterRow(row, false);
            }
        });

        tbody.addEventListener('click', function (event) {
            var button = event.target.closest('.yfg-remove');
            if (button) {
                var row = button.closest('tr');
                row.parentNode.removeChild(row);
                form.querySelector('.yfg-add').focus();
                status.textContent = 'Filter entfernt.';
            }
        });

        form.querySelector('.yfg-add').addEventListener('click', function () {
            var html = template.innerHTML.replace(/__i__/g, 'n' + (counter++));
            tbody.insertAdjacentHTML('beforeend', html);
            var row = tbody.lastElementChild;
            var select = row.querySelector('select.yfg-field');
            if (fields) {
                fillFieldSelect(select);
            }
            updateFilterRow(row, true);
            select.focus();
            status.textContent = 'Filter hinzugefügt.';
        });

        if (tableSelect.value) {
            loadFields(tableSelect.value);
        }
    }

    function boot() {
        document.querySelectorAll('form.yfg-form').forEach(init);
    }

    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', boot);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
