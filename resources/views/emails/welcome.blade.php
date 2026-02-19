<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f3f4f6;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;margin-top:32px;">
  <tr>
    <td style="background:linear-gradient(135deg,#059669,#10b981);padding:40px 32px;text-align:center;">
      <h1 style="color:#fff;margin:0;font-size:28px;">⚡ JobsHours</h1>
      <p style="color:#d1fae5;margin:8px 0 0;font-size:14px;">La comunidad de servicios cercanos</p>
    </td>
  </tr>
  <tr>
    <td style="padding:32px;">
      <h2 style="color:#111827;margin:0 0 16px;">¡Bienvenido, {{ $user->name }}! 🎉</h2>
      <p style="color:#4b5563;line-height:1.6;margin:0 0 16px;">
        Tu cuenta en <strong>JobsHours</strong> está lista.
        {{ $user->type === 'worker' ? 'Ya puedes activar tu perfil de trabajador y empezar a recibir solicitudes cerca de ti.' : 'Ya puedes publicar solicitudes y encontrar socios cerca de ti.' }}
      </p>
      <table cellpadding="0" cellspacing="0" style="margin:24px 0;">
        <tr>
          <td style="background:#059669;border-radius:12px;padding:14px 32px;">
            <a href="https://jobshour.dondemorales.cl" style="color:#fff;text-decoration:none;font-weight:bold;font-size:16px;">Ir a JobsHours →</a>
          </td>
        </tr>
      </table>
      <p style="color:#9ca3af;font-size:12px;margin:24px 0 0;">Si no creaste esta cuenta, puedes ignorar este email.</p>
    </td>
  </tr>
  <tr>
    <td style="background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">
      <p style="color:#9ca3af;font-size:12px;margin:0;">JobsHours · Renaico, Araucanía, Chile</p>
    </td>
  </tr>
</table>
</body>
</html>
