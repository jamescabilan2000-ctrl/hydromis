/**
 * Form Validation and Enhancement Module
 * Provides unified form handling and validation across HydroMIS
 */

(function() {
    'use strict';

    window.HydroMISForms = {
        // Enhanced validation rules
        validators: {
            email: (value) => {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(value);
            },
            phone: (value) => {
                // Philippine phone format: 09XX or +639XX
                const phoneRegex = /^(\+63|0)9\d{9}$/;
                return phoneRegex.test(value.replace(/[- ]/g, ''));
            },
            password: (value) => {
                // At least 8 characters, 1 uppercase, 1 number
                return value.length >= 8 && /[A-Z]/.test(value) && /\d/.test(value);
            },
            username: (value) => {
                // 3-20 characters, alphanumeric and underscore
                return /^[a-zA-Z0-9_]{3,20}$/.test(value);
            },
            fullname: (value) => {
                // At least 2 words, letters only
                return /^[a-zA-Z]+ [a-zA-Z]+/.test(value.trim());
            },
            required: (value) => {
                return value && value.trim().length > 0;
            },
            minLength: (value, length) => {
                return value.length >= length;
            },
            maxLength: (value, length) => {
                return value.length <= length;
            },
            match: (value, otherFieldId) => {
                const otherField = document.getElementById(otherFieldId);
                return otherField && value === otherField.value;
            }
        },

        // Validation messages
        messages: {
            email: 'Please enter a valid email address',
            phone: 'Please enter a valid phone number (09XX XXXX XXX)',
            password: 'Password must be at least 8 characters with uppercase and numbers',
            username: 'Username must be 3-20 characters (alphanumeric and underscore)',
            fullname: 'Please enter your full name (first and last name)',
            required: 'This field is required',
            minLength: (length) => `Must be at least ${length} characters`,
            maxLength: (length) => `Cannot exceed ${length} characters`,
            match: 'Passwords do not match'
        },

        // Initialize form validation on a specific form
        initForm: function(formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            form.addEventListener('submit', (e) => {
                if (!this.validateForm(formId)) {
                    e.preventDefault();
                }
            });

            // Real-time validation on blur
            form.querySelectorAll('[data-validate]').forEach(field => {
                field.addEventListener('blur', () => this.validateField(field));
                field.addEventListener('input', () => {
                    if (field.classList.contains('is-invalid')) {
                        this.validateField(field);
                    }
                });
            });
        },

        // Validate a single field
        validateField: function(field) {
            const rules = (field.dataset.validate || '').split('|');
            const value = field.value;
            let isValid = true;
            let errorMessage = '';

            for (const rule of rules) {
                if (rule === 'required') {
                    if (!this.validators.required(value)) {
                        isValid = false;
                        errorMessage = this.messages.required;
                        break;
                    }
                } else if (rule === 'email') {
                    if (value && !this.validators.email(value)) {
                        isValid = false;
                        errorMessage = this.messages.email;
                        break;
                    }
                } else if (rule === 'phone') {
                    if (value && !this.validators.phone(value)) {
                        isValid = false;
                        errorMessage = this.messages.phone;
                        break;
                    }
                } else if (rule === 'password') {
                    if (value && !this.validators.password(value)) {
                        isValid = false;
                        errorMessage = this.messages.password;
                        break;
                    }
                } else if (rule === 'username') {
                    if (value && !this.validators.username(value)) {
                        isValid = false;
                        errorMessage = this.messages.username;
                        break;
                    }
                } else if (rule === 'fullname') {
                    if (value && !this.validators.fullname(value)) {
                        isValid = false;
                        errorMessage = this.messages.fullname;
                        break;
                    }
                } else if (rule.startsWith('minLength:')) {
                    const length = parseInt(rule.split(':')[1]);
                    if (!this.validators.minLength(value, length)) {
                        isValid = false;
                        errorMessage = this.messages.minLength(length);
                        break;
                    }
                } else if (rule.startsWith('maxLength:')) {
                    const length = parseInt(rule.split(':')[1]);
                    if (!this.validators.maxLength(value, length)) {
                        isValid = false;
                        errorMessage = this.messages.maxLength(length);
                        break;
                    }
                } else if (rule.startsWith('match:')) {
                    const otherId = rule.split(':')[1];
                    if (!this.validators.match(value, otherId)) {
                        isValid = false;
                        errorMessage = this.messages.match;
                        break;
                    }
                }
            }

            this.displayFieldError(field, isValid, errorMessage);
            return isValid;
        },

        // Validate entire form
        validateForm: function(formId) {
            const form = document.getElementById(formId);
            if (!form) return false;

            let isFormValid = true;
            form.querySelectorAll('[data-validate]').forEach(field => {
                if (!this.validateField(field)) {
                    isFormValid = false;
                }
            });

            if (!isFormValid) {
                Notifications.error('Please fix the validation errors below');
            }

            return isFormValid;
        },

        // Display field error
        displayFieldError: function(field, isValid, errorMessage) {
            // Remove existing error
            const existingError = field.parentElement.querySelector('.form-error');
            if (existingError) {
                existingError.remove();
            }

            if (!isValid) {
                field.classList.add('is-invalid');
                const errorDiv = document.createElement('small');
                errorDiv.className = 'form-error error-text';
                errorDiv.style.cssText = 'display: block; color: #ef4444; font-size: 12px; margin-top: 4px;';
                errorDiv.innerText = errorMessage;
                field.parentElement.appendChild(errorDiv);
            } else {
                field.classList.remove('is-invalid');
            }
        },

        // Format phone number on input
        formatPhoneNumber: function(input) {
            let value = input.value.replace(/[^\d+]/g, '');
            
            // Add formatting
            if (value.startsWith('0')) {
                value = '0' + value.slice(1).slice(0, 10);
                if (value.length >= 5) {
                    value = value.slice(0, 4) + ' ' + value.slice(4);
                }
                if (value.length >= 9) {
                    value = value.slice(0, 9) + ' ' + value.slice(9);
                }
            } else if (value.startsWith('+63')) {
                if (value.length >= 6) {
                    value = '+63' + value.slice(3, 4) + ' ' + value.slice(4);
                }
                if (value.length >= 11) {
                    value = value.slice(0, 11) + ' ' + value.slice(11);
                }
            }
            
            input.value = value;
        },

        // Set up all forms on page
        initAllForms: function() {
            const forms = document.querySelectorAll('form[id]');
            forms.forEach(form => {
                this.initForm(form.id);
            });
        }
    };

    // Auto-initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        HydroMISForms.initAllForms();
    });

    // Enhanced Bootstrap form styling
    const style = document.createElement('style');
    style.textContent = `
        .form-control:focus {
            border-color: #0f4fd4 !important;
            box-shadow: 0 0 0 0.2rem rgba(15, 79, 212, 0.25) !important;
        }

        .form-control.is-invalid {
            border-color: #ef4444 !important;
            background-color: #fef2f2 !important;
        }

        .form-control.is-invalid:focus {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 0.2rem rgba(239, 68, 68, 0.25) !important;
        }

        .form-error {
            display: block;
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .form-group label {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 10px 12px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle button {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
        }

        .password-toggle button:hover {
            color: #0f4fd4;
        }
    `;
    document.head.appendChild(style);

})();

// Password visibility toggle
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
    }
}
