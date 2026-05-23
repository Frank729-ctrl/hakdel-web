	# hakdel-web

Next.js 14 frontend for HakDel — a cybersecurity training platform.

## Stack
- **Framework**: Next.js 14 App Router
- **Auth**: NextAuth.js v4 (credentials + Google OAuth)
- **ORM**: Prisma + PostgreSQL
- **Styling**: Tailwind CSS (custom dark design system)
- **Backend API**: FastAPI at https://hakdel.onrender.com

## File Structure

```
hakdel-web/
├── app/
│   ├── (auth)/
│   │   ├── layout.tsx          # Split-panel auth layout
│   │   ├── login/page.tsx
│   │   ├── register/page.tsx
│   │   └── forgot-password/page.tsx
│   ├── (protected)/
│   │   ├── layout.tsx          # Auth guard + sidebar shell
│   │   ├── dashboard/page.tsx
│   │   ├── scanner/
│   │   │   ├── page.tsx        # Live scanner UI
│   │   │   └── history/page.tsx
│   │   ├── tools/
│   │   │   ├── ip/page.tsx
│   │   │   ├── cve/page.tsx
│   │   │   ├── ports/page.tsx
│   │   │   ├── headers/page.tsx
│   │   │   ├── domain/page.tsx
│   │   │   └── hash/page.tsx
│   │   ├── leaderboard/page.tsx
│   │   ├── profile/page.tsx
│   │   ├── notifications/page.tsx
│   │   ├── settings/page.tsx
│   │   ├── labs/page.tsx
│   │   ├── quiz/page.tsx
│   │   └── upgrade/page.tsx
│   ├── api/
│   │   ├── auth/
│   │   │   ├── [...nextauth]/route.ts
│   │   │   ├── register/route.ts
│   │   │   ├── verify-email/route.ts
│   │   │   ├── forgot-password/route.ts
│   │   │   └── reset-password/route.ts
│   │   ├── scanner/
│   │   │   ├── start/route.ts
│   │   │   ├── status/[jobId]/route.ts
│   │   │   └── save/route.ts
│   │   ├── tools/
│   │   │   ├── ip/route.ts
│   │   │   ├── cve/route.ts
│   │   │   ├── ports/route.ts
│   │   │   ├── headers/route.ts
│   │   │   ├── domain/route.ts
│   │   │   └── hash/route.ts
│   │   └── user/
│   │       └── change-password/route.ts
│   ├── globals.css
│   └── layout.tsx
├── components/
│   ├── layout/
│   │   ├── sidebar.tsx
│   │   └── header.tsx
│   └── tools/
│       └── tool-shell.tsx
├── lib/
│   ├── auth.ts
│   ├── db.ts
│   ├── mail.ts
│   └── utils.ts
├── prisma/
│   └── schema.prisma
├── types/
│   └── next-auth.d.ts
├── package.json
├── tailwind.config.js
├── tsconfig.json
└── vercel.json
```

## Environment Variables

```env
DATABASE_URL=postgresql://...
NEXTAUTH_URL=https://your-domain.vercel.app
NEXTAUTH_SECRET=<random 32-char string>
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
SCANNER_API_URL=https://hakdel.onrender.com
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM=noreply@hakdel.io
ABUSEIPDB_API_KEY=
VIRUSTOTAL_API_KEY=
SHODAN_API_KEY=
NVD_API_KEY=
ANTHROPIC_API_KEY=
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
```

## Setup

```bash
npm install
npx prisma db push
npm run dev
```
