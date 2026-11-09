/**
 * Laboratory Equipment Reservation System (LERS)
 * Interactive Client Scripts (Vanilla JS - Zero Dependencies)
 * Author: Prishan Samarasinghe (MSc IT)
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Demo Credentials Quick-Fill for Evaluators / Thesis Defense
    const demoButtons = document.querySelectorAll('.btn-demo-pill');
    if (demoButtons.length > 0) {
        demoButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const email = this.getAttribute('data-email');
                const password = this.getAttribute('data-password');
                const role = this.getAttribute('data-role');

                const identifierInput = document.getElementById('identifier');
                const passwordInput = document.getElementById('password');
                if (identifierInput) identifierInput.value = email;
                if (passwordInput) passwordInput.value = password;

                // Check corresponding role radio button
                const roleRadio = document.querySelector(`input[name="role"][value="${role}"]`);
                if (roleRadio) {
                    roleRadio.checked = true;
                }
            });
        });
    }

    // 2. Interactive 24-Hour Slot Picker (Screen 03)
    const slotCells = document.querySelectorAll('.slot-cell.free');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    const durationDisplay = document.getElementById('duration_calc');

    if (slotCells.length > 0 && startTimeInput) {
        slotCells.forEach(cell => {
            cell.addEventListener('click', function () {
                // Remove previous selected highlight
                document.querySelectorAll('.slot-cell.selected').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');

                const slotHour = parseInt(this.getAttribute('data-hour'), 10);
                const baseDate = this.getAttribute('data-date'); // YYYY-MM-DD

                // Format hour strings: HH:00
                const startHourStr = String(slotHour).padStart(2, '0') + ':00';
                const endHourStr = String(slotHour + 2).padStart(2, '0') + ':00'; // Default 2 hour continuous slot

                startTimeInput.value = `${baseDate}T${startHourStr}`;
                if (endTimeInput) {
                    endTimeInput.value = `${baseDate}T${endHourStr}`;
                }

                if (durationDisplay) {
                    durationDisplay.textContent = '2.0 Hours Selected';
                }
            });
        });
    }

    // 3. Dynamic End Time Calculator
    if (startTimeInput && endTimeInput && durationDisplay) {
        const updateDuration = function () {
            if (startTimeInput.value && endTimeInput.value) {
                const start = new Date(startTimeInput.value);
                const end = new Date(endTimeInput.value);
                const diffMs = end - start;
                if (diffMs > 0) {
                    const diffHours = (diffMs / (1000 * 60 * 60)).toFixed(1);
                    durationDisplay.textContent = `${diffHours} Hours`;
                    if (diffHours > 4.0) {
                        durationDisplay.style.color = '#b91c1c';
                        durationDisplay.textContent += ' (Exceeds Max 4h Policy!)';
                    } else {
                        durationDisplay.style.color = '#15803d';
                    }
                } else {
                    durationDisplay.textContent = 'End time must be after start time';
                    durationDisplay.style.color = '#b91c1c';
                }
            }
        };

        startTimeInput.addEventListener('change', updateDuration);
        endTimeInput.addEventListener('change', updateDuration);
    }
});
