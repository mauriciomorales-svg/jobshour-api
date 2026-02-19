<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f3f4f6;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;margin-top:32px;">
  <tr>
    <td style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:40px 32px;text-align:center;">
      <h1 style="color:#fff;margin:0;font-size:28px;">🎉 ¡Servicio completado!</h1>
    </td>
  </tr>
  <tr>
    <td style="padding:32px;">
      <p style="color:#4b5563;line-height:1.6;margin:0 0 16px;">
        Tu solicitud fue completada exitosamente:
      </p>
      <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:16px;margin:0 0 16px;">
        <p style="color:#581c87;margin:0;font-weight:bold;">{{ $serviceRequest->description }}</p>
        @if($serviceRequest->offered_price)
        <p style="color:#7e22ce;margin:8px 0 0;font-size:14px;">Monto: ${{ number_format($serviceRequest->offered_price, 0, ',', '.') }}</p>
        @endif
      </div>
      <p style="color:#4b5563;line-height:1.6;margin:0 0 16px;">
        ¿Cómo fue tu experiencia? Tu calificación ayuda a mejorar la comunidad.
      </p>
      <table cellpadding="0" cellspacing="0" style="margin:24px 0;">
        <tr>
          <td style="background:#7c3aed;border-radius:12px;padding:14px 32px;">
            <a href="https://jobshour.dondemorales.cl" style="color:#fff;text-decoration:none;font-weight:bold;font-size:16px;">Calificar al socio ⭐</a>
          </td>
        </tr>
      </table>
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
