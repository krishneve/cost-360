/**
 * Cost360 - Enterprise Login Logic
 * Theme: Manufacturing Cost Intelligence & Financial Analytics Platform
 */

document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('loginForm');
  const emailInput = document.getElementById('emailInput');
  const passwordInput = document.getElementById('passwordInput');
  const togglePasswordBtn = document.getElementById('togglePasswordBtn');
  const eyeIconOpen = togglePasswordBtn ? togglePasswordBtn.querySelector('.eyeIconOpen') : null;
  const eyeIconClosed = togglePasswordBtn ? togglePasswordBtn.querySelector('.eyeIconClosed') : null;
  const submitBtn = document.getElementById('submitBtn');
  const btnSpinner = submitBtn ? submitBtn.querySelector('.btnSpinner') : null;
  const btnText = submitBtn ? submitBtn.querySelector('.btnText') : null;
  const feedbackAlert = document.getElementById('loginAlert');
  const alertText = feedbackAlert ? feedbackAlert.querySelector('.alertText') : null;

  // Modals for Forgot Password & Support
  const forgotPasswordLink = document.getElementById('forgotPasswordLink');
  const contactSupportLink = document.getElementById('contactSupportLink');
  const infoModalBackdrop = document.getElementById('infoModalBackdrop');
  const infoModalTitle = document.getElementById('infoModalTitle');
  const infoModalBody = document.getElementById('infoModalBody');
  const infoModalCloseBtn = document.getElementById('infoModalCloseBtn');

  /* ------------------------------------------------------------------------
   * 1. PASSWORD VISIBILITY TOGGLE
   * ------------------------------------------------------------------------ */
  if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const isPassword = passwordInput.getAttribute('type') === 'password';
      passwordInput.setAttribute('type', isPassword ? 'text' : 'password');

      if (eyeIconOpen && eyeIconClosed) {
        if (isPassword) {
          eyeIconOpen.style.display = 'none';
          eyeIconClosed.style.display = 'block';
          togglePasswordBtn.setAttribute('aria-label', 'Hide password');
        } else {
          eyeIconOpen.style.display = 'block';
          eyeIconClosed.style.display = 'none';
          togglePasswordBtn.setAttribute('aria-label', 'Show password');
        }
      }

      passwordInput.focus();
    });
  }

  /* ------------------------------------------------------------------------
   * 2. ALERT & VALIDATION HELPERS
   * ------------------------------------------------------------------------ */
  function showAlert(message, type = 'error') {
    if (!feedbackAlert || !alertText) return;
    alertText.textContent = message;
    feedbackAlert.className = `feedback-alert feedbackAlert show ${type}`;
  }

  function hideAlert() {
    if (!feedbackAlert) return;
    feedbackAlert.className = 'feedback-alert feedbackAlert';
  }

  function clearInputErrors() {
    document.querySelectorAll('.input-wrapper.has-error').forEach((wrapper) => {
      wrapper.classList.remove('has-error');
    });
  }

  // Clear errors when user types
  if (emailInput) {
    emailInput.addEventListener('input', () => {
      emailInput.closest('.input-wrapper')?.classList.remove('has-error');
      if (feedbackAlert?.classList.contains('show')) {
        hideAlert();
      }
    });
  }

  if (passwordInput) {
    passwordInput.addEventListener('input', () => {
      passwordInput.closest('.input-wrapper')?.classList.remove('has-error');
      if (feedbackAlert?.classList.contains('show')) {
        hideAlert();
      }
    });
  }

  /* ------------------------------------------------------------------------
   * 3. ASYNCHRONOUS FORM SUBMISSION & AUTHENTICATION FLOW
   * ------------------------------------------------------------------------ */
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      e.preventDefault();
      clearInputErrors();
      hideAlert();

      const emailVal = emailInput ? emailInput.value.trim() : '';
      const passwordVal = passwordInput ? passwordInput.value : '';

      // Client-side format checks
      if (!emailVal) {
        showAlert('Please enter your email address.');
        emailInput?.closest('.input-wrapper')?.classList.add('has-error');
        emailInput?.focus();
        return;
      }

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(emailVal)) {
        showAlert('Please enter a valid email address.');
        emailInput?.closest('.input-wrapper')?.classList.add('has-error');
        emailInput?.focus();
        return;
      }

      if (!passwordVal) {
        showAlert('Please enter your password.');
        passwordInput?.closest('.input-wrapper')?.classList.add('has-error');
        passwordInput?.focus();
        return;
      }

      // Reset & UI states
      const resetBtnState = () => {
        if (submitBtn) submitBtn.disabled = false;
        if (btnSpinner) btnSpinner.style.display = 'none';
        if (btnText) {
          btnText.innerHTML = `
            <span>SIGN IN</span>
            <svg class="btn-arrow-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          `;
        }
      };

      // Set Loading UI
      if (submitBtn) submitBtn.disabled = true;
      if (btnSpinner) btnSpinner.style.display = 'inline-block';
      if (btnText) {
        btnText.innerHTML = '<span>SIGNING IN...</span>';
      }

      const formData = new FormData(loginForm);
      formData.append('is_ajax', '1');

      // 8-second request timeout controller
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 8000);

      fetch('login.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        signal: controller.signal
      })
        .then((res) => {
          clearTimeout(timeoutId);
          return res.json();
        })
        .then((data) => {
          if (data.success) {
            window.location.href = data.redirect || 'dashboard.php';
          } else {
            resetBtnState();
            showAlert(data.error || 'Invalid email or password.');
            passwordInput?.closest('.input-wrapper')?.classList.add('has-error');
          }
        })
        .catch((err) => {
          clearTimeout(timeoutId);
          resetBtnState();
          if (err.name === 'AbortError') {
            showAlert('Connection timed out. Please try again.');
          } else {
            showAlert('Unable to connect to server. Please try again.');
          }
        });
    });
  }

  /* ------------------------------------------------------------------------
   * 4. FORGOT PASSWORD & CONTACT SUPPORT MODAL HANDLERS
   * ------------------------------------------------------------------------ */
  function openInfoModal(title, message) {
    if (!infoModalBackdrop || !infoModalTitle || !infoModalBody) return;
    infoModalTitle.textContent = title;
    infoModalBody.textContent = message;
    infoModalBackdrop.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    infoModalCloseBtn?.focus();
  }

  function closeInfoModal() {
    if (!infoModalBackdrop) return;
    infoModalBackdrop.style.display = 'none';
    document.body.style.overflow = '';
  }

  if (forgotPasswordLink) {
    forgotPasswordLink.addEventListener('click', (e) => {
      e.preventDefault();
      openInfoModal(
        'Password Recovery',
        'Cost360 password recovery is managed by your internal organization administrator. Please contact your system administrator to initiate a password reset.'
      );
    });
  }

  if (contactSupportLink) {
    contactSupportLink.addEventListener('click', (e) => {
      e.preventDefault();
      openInfoModal(
        'Cost360 Support',
        'Need assistance accessing your Cost360 account? Please contact your organization administrator or email our enterprise support team at support@cost360.io.'
      );
    });
  }

  if (infoModalCloseBtn) {
    infoModalCloseBtn.addEventListener('click', closeInfoModal);
  }

  if (infoModalBackdrop) {
    infoModalBackdrop.addEventListener('click', (e) => {
      if (e.target === infoModalBackdrop) {
        closeInfoModal();
      }
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && infoModalBackdrop?.style.display === 'flex') {
      closeInfoModal();
    }
  });
});
