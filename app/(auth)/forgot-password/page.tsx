'use client'

import { useState } from 'react'
import Link from 'next/link'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    await fetch('/api/auth/forgot-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    })
    setLoading(false); setDone(true)
  }

  if (done) {
    return (
      <div className="text-center">
        <div className="w-14 h-14 bg-accent/10 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">📧</div>
        <h2 className="text-xl font-bold text-ink mb-2">Email sent</h2>
        <p className="text-sm text-dim mb-6">If an account exists for <span className="text-ink">{email}</span>, you&apos;ll receive a reset link shortly.</p>
        <Link href="/login" className="btn-secondary">Back to Sign In</Link>
      </div>
    )
  }

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-mono text-muted mb-1">// CREDENTIAL RECOVERY</p>
        <h1 className="text-2xl font-bold text-ink">Reset password</h1>
        <p className="text-sm text-dim mt-1">Enter your email and we&apos;ll send a reset link.</p>
      </div>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="label">Email</label>
          <input className="input" type="email" placeholder="you@example.com" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <button type="submit" className="btn-primary w-full" disabled={loading}>
          {loading ? 'Sending…' : 'Send reset link →'}
        </button>
      </form>
      <p className="text-center text-xs text-muted mt-6">
        <Link href="/login" className="text-accent hover:underline">← Back to Sign In</Link>
      </p>
    </div>
  )
}
