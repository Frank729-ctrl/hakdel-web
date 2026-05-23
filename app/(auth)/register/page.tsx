'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import Link from 'next/link'

export default function RegisterPage() {
  const router = useRouter()
  const [form, setForm] = useState({ username: '', email: '', password: '' })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(false)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError('')

    const res = await fetch('/api/auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    const data = await res.json()
    setLoading(false)

    if (!res.ok) setError(data.error ?? 'Registration failed.')
    else setDone(true)
  }

  if (done) {
    return (
      <div className="text-center">
        <div className="w-14 h-14 bg-success/10 rounded-full flex items-center justify-center mx-auto mb-4">
          <span className="text-2xl">✓</span>
        </div>
        <h2 className="text-xl font-bold text-ink mb-2">Check your email</h2>
        <p className="text-sm text-dim mb-6">
          We sent a verification link to <span className="text-ink">{form.email}</span>.
          Click it to activate your account.
        </p>
        <Link href="/login" className="btn-primary">Go to Sign In</Link>
      </div>
    )
  }

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-mono text-muted mb-1">// NEW OPERATOR</p>
        <h1 className="text-2xl font-bold text-ink">Create account</h1>
        <p className="text-sm text-dim mt-1">Join thousands of security professionals.</p>
      </div>

      {error && (
        <div className="mb-4 p-3 rounded bg-danger/10 border border-danger/20 text-danger text-sm">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="label">Username</label>
          <input
            className="input"
            type="text"
            placeholder="coolhacker"
            value={form.username}
            onChange={(e) => setForm({ ...form, username: e.target.value })}
            required
            minLength={3}
            maxLength={32}
          />
        </div>
        <div>
          <label className="label">Email</label>
          <input
            className="input"
            type="email"
            placeholder="you@example.com"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
        </div>
        <div>
          <label className="label">Password</label>
          <input
            className="input"
            type="password"
            placeholder="min 8 characters"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required
            minLength={8}
          />
        </div>

        <button type="submit" className="btn-primary w-full" disabled={loading}>
          {loading ? 'Creating account…' : 'Create account →'}
        </button>
      </form>

      <p className="text-center text-xs text-muted mt-6">
        Already have an account?{' '}
        <Link href="/login" className="text-accent hover:underline">Sign in →</Link>
      </p>
    </div>
  )
}
