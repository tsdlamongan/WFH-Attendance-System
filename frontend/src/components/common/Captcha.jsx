import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import ReCAPTCHA from 'react-google-recaptcha';

const IS_E2E = import.meta.env.VITE_E2E_TEST === 'true';

/**
 * E2E stand-in for the reCAPTCHA widget.
 *
 * The real Google widget renders a cross-origin iframe Playwright can't drive,
 * so under E2E we render a checkbox that mints a fresh single-use token on each
 * (re)check and can be reset — mirroring the real widget's lifecycle. It starts
 * pre-verified so existing flows that don't touch the captcha keep passing.
 */
const MockCaptcha = forwardRef(function MockCaptcha({ onChange }, ref) {
  const counterRef = useRef(1);
  const [checked, setChecked] = useState(true);

  useImperativeHandle(ref, () => ({
    reset: () => {
      setChecked(false);
      onChange(null);
    },
  }), [onChange]);

  const handleClick = () => {
    if (checked) {
      setChecked(false);
      onChange(null);
    } else {
      counterRef.current += 1;
      setChecked(true);
      onChange(`e2e-test-token-${counterRef.current}`);
    }
  };

  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={checked}
      aria-label="Verifikasi reCAPTCHA (E2E)"
      data-testid="mock-captcha"
      data-checked={checked}
      onClick={handleClick}
      className="border-2 border-ink rounded-card bg-white px-4 py-3 font-sans text-sm shadow-brutal"
    >
      {checked ? '☑ Terverifikasi (E2E)' : '☐ Belum diverifikasi (E2E)'}
    </button>
  );
});

const RealCaptcha = forwardRef(function RealCaptcha({ onChange }, ref) {
  const innerRef = useRef(null);

  useImperativeHandle(ref, () => ({
    reset: () => innerRef.current?.reset(),
  }), []);

  return (
    <div className="w-[302px] h-[76px] overflow-hidden">
      <ReCAPTCHA
        ref={innerRef}
        sitekey={import.meta.env.VITE_RECAPTCHA_SITE_KEY}
        onChange={onChange}
        theme="light"
      />
    </div>
  );
});

/**
 * reCAPTCHA widget with an imperative `reset()` handle.
 *
 * Tokens are single-use: once submitted they are consumed server-side, so after
 * a failed submit the caller must `reset()` this widget to force a fresh token
 * instead of resending the stale one (which fails verification).
 */
export const Captcha = forwardRef(function Captcha({ onChange }, ref) {
  return IS_E2E
    ? <MockCaptcha ref={ref} onChange={onChange} />
    : <RealCaptcha ref={ref} onChange={onChange} />;
});
