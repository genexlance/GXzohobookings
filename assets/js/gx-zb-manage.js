(function() {
    'use strict';

    var gxZb = window.gxZbManage || {};

    // ---- Helper: default fetch wrapper ----
    function gxFetch(action, data) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('_ajax_nonce', gxZb.nonce || '');
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }
        return fetch(gxZb.ajaxUrl || ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        });
    }

    // ---- Booking Form logic ----
    function initBookingForm() {
        var serviceSelect = document.getElementById('gx-zb-service');
        var staffSelect = document.getElementById('gx-zb-staff');
        var dateInput = document.getElementById('gx-zb-date');
        var slotContainer = document.getElementById('gx-zb-slots');
        var slotHidden = document.getElementById('gx-zb-slot');
        var submitBtn = document.getElementById('gx-zb-submit');

        if (!serviceSelect || !staffSelect || !dateInput || !slotContainer || !slotHidden || !submitBtn) {
            return; // not the booking form page
        }

        // Disable submit until slot selected
        submitBtn.disabled = true;

        // Load staff on service change
        serviceSelect.addEventListener('change', function() {
            var serviceId = this.value;
            if (!serviceId) {
                staffSelect.innerHTML = '<option value="">' + gxZb.strings.selectServiceFirst + '</option>';
                return;
            }
            gxFetch('gx_zb_get_staff', { service_id: serviceId }).then(function(json) {
                if (json.success && json.data) {
                    var opts = '<option value="">' + gxZb.strings.selectStaff + '</option>';
                    json.data.forEach(function(s) {
                        opts += '<option value="' + escAttr(s.id) + '">' + escHtml(s.name) + '</option>';
                    });
                    staffSelect.innerHTML = opts;
                }
            }).catch(function() {
                staffSelect.innerHTML = '<option value="">' + gxZb.strings.errorLoading + '</option>';
            });
        });

        // Load slots on staff/date change
        function loadSlots() {
            var serviceId = serviceSelect.value;
            var staffId = staffSelect.value;
            var dateVal = dateInput.value;
            if (!serviceId || !staffId || !dateVal) {
                slotContainer.innerHTML = '';
                submitBtn.disabled = true;
                return;
            }
            gxFetch('gx_zb_get_slots', {
                service_id: serviceId,
                staff_id: staffId,
                date: dateVal
            }).then(function(json) {
                if (json.success && json.data && json.data.length > 0) {
                    var buttons = '';
                    json.data.forEach(function(slot) {
                        buttons += '<button type="button" class="gx-zb-slot-btn" data-slot="' + escAttr(slot) + '">' + escHtml(slot) + '</button>';
                    });
                    slotContainer.innerHTML = buttons;
                    // Attach click listeners to slot buttons
                    var btns = slotContainer.querySelectorAll('.gx-zb-slot-btn');
                    for (var i = 0; i < btns.length; i++) {
                        btns[i].addEventListener('click', function() {
                            // Remove is-selected from all
                            var all = slotContainer.querySelectorAll('.gx-zb-slot-btn');
                            for (var j = 0; j < all.length; j++) {
                                all[j].classList.remove('is-selected');
                            }
                            this.classList.add('is-selected');
                            slotHidden.value = this.getAttribute('data-slot');
                            submitBtn.disabled = false;
                        });
                    }
                } else {
                    slotContainer.innerHTML = '<p class="gx-zb-no-slots">' + gxZb.strings.noSlots + '</p>';
                    submitBtn.disabled = true;
                }
            }).catch(function() {
                slotContainer.innerHTML = '<p class="gx-zb-error">' + gxZb.strings.errorLoading + '</p>';
                submitBtn.disabled = true;
            });
        }

        staffSelect.addEventListener('change', loadSlots);
        dateInput.addEventListener('change', loadSlots);

        // Reset slot on service change
        serviceSelect.addEventListener('change', function() {
            slotContainer.innerHTML = '';
            slotHidden.value = '';
            submitBtn.disabled = true;
        });
    }

    // ---- Confirm actions (links + destructive POST forms) ----
    function initAppointmentActions() {
        var confirmLinks = document.querySelectorAll('a.gx-zb-action-confirm');
        for (var i = 0; i < confirmLinks.length; i++) {
            confirmLinks[i].addEventListener('click', function(e) {
                var msg = this.getAttribute('data-confirm');
                if (msg && !confirm(msg)) {
                    e.preventDefault();
                }
            });
        }
        var confirmForms = document.querySelectorAll('form.gx-zb-confirm-form');
        for (var j = 0; j < confirmForms.length; j++) {
            confirmForms[j].addEventListener('submit', function(e) {
                var msg = this.getAttribute('data-confirm');
                if (msg && !confirm(msg)) {
                    e.preventDefault();
                }
            });
        }
    }

    // ---- Init on DOM ready ----
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initBookingForm();
            initAppointmentActions();
        });
    } else {
        initBookingForm();
        initAppointmentActions();
    }

    // ---- Utility escape functions (basic) ----
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&#34;').replace(/'/g, '&#39;');
    }

})();
