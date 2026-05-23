import nodemailer from 'nodemailer'

const transporter = nodemailer.createTransport({
  host: process.env.SMTP_HOST,
  port: Number(process.env.SMTP_PORT ?? 465),
  secure: true,
  auth: {
    user: process.env.SMTP_USER,
    pass: process.env.SMTP_PASS,
  },
})

export async function sendMail(to: string, subject: string, html: string) {
  await transporter.sendMail({
    from: process.env.SMTP_FROM ?? 'HakDel <noreply@hakdel.app>',
    to,
    subject,
    html,
  })
}

export function verifyEmailTemplate(username: string, url: string) {
  return `
    <div style="font-family:Inter,sans-serif;background:#080a0f;color:#e2e8f0;padding:40px;border-radius:12px;max-width:480px;margin:0 auto">
      <h1 style="color:#4171ff;margin:0 0 8px">HakDel</h1>
      <p style="color:#94a3b8;margin:0 0 32px;font-size:13px">SECURITY TRAINING PLATFORM</p>
      <h2 style="margin:0 0 16px">Verify your email</h2>
      <p style="color:#94a3b8;margin:0 0 24px">Hey ${username}, click the button below to verify your email and activate your account.</p>
      <a href="${url}" style="display:inline-block;background:#4171ff;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600">Verify Email</a>
      <p style="color:#64748b;font-size:12px;margin:24px 0 0">Link expires in 24 hours. If you didn't sign up, ignore this email.</p>
    </div>`
}

export function resetPasswordTemplate(username: string, url: string) {
  return `
    <div style="font-family:Inter,sans-serif;background:#080a0f;color:#e2e8f0;padding:40px;border-radius:12px;max-width:480px;margin:0 auto">
      <h1 style="color:#4171ff;margin:0 0 8px">HakDel</h1>
      <p style="color:#94a3b8;margin:0 0 32px;font-size:13px">SECURITY TRAINING PLATFORM</p>
      <h2 style="margin:0 0 16px">Reset your password</h2>
      <p style="color:#94a3b8;margin:0 0 24px">Hey ${username}, click below to set a new password. This link expires in 1 hour.</p>
      <a href="${url}" style="display:inline-block;background:#4171ff;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600">Reset Password</a>
      <p style="color:#64748b;font-size:12px;margin:24px 0 0">If you didn't request this, ignore this email.</p>
    </div>`
}
