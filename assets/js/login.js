/**
 * Cost360 - Login UI Vanilla JavaScript (Desktop + Mobile Responsive)
 * Theme: Enterprise Cost Management & Manufacturing Analytics Platform
 */

document.addEventListener('DOMContentLoaded', () => {
  // Check if reduced motion is preferred by user
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------------
   * 1. PASSWORD VISIBILITY TOGGLE (Handles both Desktop and Mobile Forms)
   * ------------------------------------------------------------------------ */
  const togglePasswordBtns = document.querySelectorAll('.togglePassword');
  
  togglePasswordBtns.forEach((btn) => {
    btn.addEventListener('click', function() {
      const inputWrapper = this.closest('.input-wrapper');
      if (!inputWrapper) return;

      const passwordInput = inputWrapper.querySelector('.passwordInput');
      const eyeIconOpen = inputWrapper.querySelector('.eyeIconOpen');
      const eyeIconClosed = inputWrapper.querySelector('.eyeIconClosed');

      if (passwordInput) {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');

        if (eyeIconOpen && eyeIconClosed) {
          if (isPassword) {
            eyeIconOpen.style.display = 'none';
            eyeIconClosed.style.display = 'block';
            btn.setAttribute('aria-label', 'Hide password');
          } else {
            eyeIconOpen.style.display = 'block';
            eyeIconClosed.style.display = 'none';
            btn.setAttribute('aria-label', 'Show password');
          }
        }
      }
    });
  });

  /* ------------------------------------------------------------------------
   * 2. FAST ASYNCHRONOUS FORM SUBMISSION & FEEDBACK (No Infinite Hangs)
   * ------------------------------------------------------------------------ */
  const loginForms = document.querySelectorAll('.loginForm');

  loginForms.forEach((form) => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      const submitBtn = this.querySelector('.submitBtn');
      const btnText = this.querySelector('.btnText');
      const btnSpinner = this.querySelector('.btnSpinner');
      
      // Find alert container for current layout
      const parentCard = this.closest('.login-card') || this.closest('.mobile-content-container');
      const feedbackAlert = parentCard ? parentCard.querySelector('.feedbackAlert') : null;
      const alertText = feedbackAlert ? feedbackAlert.querySelector('.alertText') : null;

      // Function to reset button state
      const resetButton = () => {
        if (submitBtn) submitBtn.disabled = false;
        if (btnSpinner) btnSpinner.style.display = 'none';
        if (btnText) {
          btnText.innerHTML = 'SIGN IN <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
        }
      };

      // Show loading UI
      if (submitBtn) submitBtn.disabled = true;
      if (btnSpinner) btnSpinner.style.display = 'inline-block';
      if (btnText) btnText.textContent = 'Signing in...';

      if (feedbackAlert) {
        feedbackAlert.classList.remove('show', 'error', 'info');
      }

      const formData = new FormData(this);
      formData.append('is_ajax', '1');

      // Abort controller with 5 second hard timeout
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 5000);

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
          resetButton();
          if (feedbackAlert && alertText) {
            alertText.textContent = data.error || 'Invalid email or password.';
            feedbackAlert.classList.add('error', 'show');
          }
        }
      })
      .catch((err) => {
        clearTimeout(timeoutId);
        resetButton();
        if (feedbackAlert && alertText) {
          if (err.name === 'AbortError') {
            alertText.textContent = 'Connection timed out. Database host is unreachable.';
          } else {
            alertText.textContent = 'Unable to connect to server. Please try again.';
          }
          feedbackAlert.classList.add('error', 'show');
        }
      });
    });
  });

  /* ------------------------------------------------------------------------
   * 3. DESKTOP MOUSE PARALLAX EFFECT (> 768px only)
   * ------------------------------------------------------------------------ */
  const brandPanel = document.querySelector('.brand-panel');
  const leftHeroImg = document.querySelector('.left-hero-img');

  if (brandPanel && leftHeroImg && !prefersReducedMotion && window.innerWidth > 768) {
    let mouseX = 0;
    let mouseY = 0;
    let targetX = 0;
    let targetY = 0;

    brandPanel.addEventListener('mousemove', (e) => {
      const rect = brandPanel.getBoundingClientRect();
      const x = e.clientX - rect.left - rect.width / 2;
      const y = e.clientY - rect.top - rect.height / 2;

      targetX = (x / (rect.width / 2)) * 6;
      targetY = (y / (rect.height / 2)) * 6;
    });

    brandPanel.addEventListener('mouseleave', () => {
      targetX = 0;
      targetY = 0;
    });

    function animateParallax() {
      mouseX += (targetX - mouseX) * 0.06;
      mouseY += (targetY - mouseY) * 0.06;

      leftHeroImg.style.transform = `translate3d(${mouseX.toFixed(2)}px, ${mouseY.toFixed(2)}px, 0)`;
      requestAnimationFrame(animateParallax);
    }

    animateParallax();
  }

  /* ------------------------------------------------------------------------
   * 4. CANVAS PARTICLE SYSTEM (Desktop & Mobile Canvases)
   * ------------------------------------------------------------------------ */
  function initParticleCanvas(canvasId, particleCount) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || prefersReducedMotion) return;

    const ctx = canvas.getContext('2d');
    let width = (canvas.width = canvas.parentElement.offsetWidth);
    let height = (canvas.height = canvas.parentElement.offsetHeight);

    window.addEventListener('resize', () => {
      if (canvas.parentElement) {
        width = canvas.width = canvas.parentElement.offsetWidth;
        height = canvas.height = canvas.parentElement.offsetHeight;
      }
    });

    const particles = [];
    for (let i = 0; i < particleCount; i++) {
      particles.push({
        x: Math.random() * width,
        y: Math.random() * height,
        radius: Math.random() * 1.8 + 0.6,
        speedX: (Math.random() - 0.5) * 0.3,
        speedY: (Math.random() - 0.5) * 0.3,
        opacity: Math.random() * 0.45 + 0.15
      });
    }

    function renderParticles() {
      ctx.clearRect(0, 0, width, height);

      particles.forEach((p) => {
        p.x += p.speedX;
        p.y += p.speedY;

        if (p.x < 0) p.x = width;
        if (p.x > width) p.x = 0;
        if (p.y < 0) p.y = height;
        if (p.y > height) p.y = 0;

        ctx.beginPath();
        ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(0, 207, 239, ${p.opacity})`;
        ctx.shadowBlur = 6;
        ctx.shadowColor = '#00CFEF';
        ctx.fill();
      });

      requestAnimationFrame(renderParticles);
    }

    renderParticles();
  }

  initParticleCanvas('particles-canvas-desktop', 25);
  initParticleCanvas('particles-canvas-mobile', 15);
});
