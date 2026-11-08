import { Link } from 'react-router-dom';
import AuthShell from '../components/auth/AuthShell';

const CONTENT = {
  terms: {
    brand: {
      title: 'Terms of Service',
      lead: 'The rules for using the LIB Monitoring System at Mindanao State University – Main Campus.',
      items: [
        'Use your own MSU account',
        'Access only authorized records',
        'Keep your information accurate',
      ],
    },
    intro: 'These terms cover use of the LIB Monitoring System. By requesting an account or signing in, you agree to use the system responsibly and in line with MSU policies.',
    sections: [
      {
        heading: 'Accounts',
        body: 'Access is requested with an official @msumain.edu.ph email address. New accounts remain pending until a Program Leader reviews the request and assigns a role. Keep your credentials private and do not share your account with anyone else.',
      },
      {
        heading: 'Authorized use',
        body: 'Use the system only to monitor externally funded research budgets, record releases, review realignments, and prepare reports for authorized MSU personnel. Do not attempt to access records, projects, or accounts you are not authorized to view.',
      },
      {
        heading: 'Accurate information',
        body: 'You confirm that the details you submit are accurate and agree to keep your contact and employment information current.',
      },
      {
        heading: 'Availability and changes',
        body: 'The system may be unavailable during maintenance or updates. Features and these terms may be updated as institutional requirements change.',
      },
      {
        heading: 'Questions',
        body: 'Contact the Program Leader or the MSU IT office for questions about access or these terms.',
      },
    ],
  },
  privacy: {
    brand: {
      title: 'Privacy Policy',
      lead: 'How the LIB Monitoring System handles your personal information under the Data Privacy Act of 2012.',
      items: [
        'We collect only what is needed',
        'Access is limited to authorized staff',
        'You can request correction of your data',
      ],
    },
    intro: 'This notice explains what personal information the LIB Monitoring System collects and how it is used. It does not replace the University’s official privacy policies.',
    sections: [
      {
        heading: 'Information we collect',
        body: 'We collect your first and last name, MSU email address, contact number, employee ID, position, college, department, account status, and the activity needed to operate and audit the system.',
      },
      {
        heading: 'Why we use it',
        body: 'Your information is used to review and approve accounts, assign roles, monitor research budgets, generate reports, maintain audit trails, and protect the system from unauthorized access.',
      },
      {
        heading: 'Who can access it',
        body: 'Access is limited to authorized MSU personnel with a legitimate role, including Program Leaders, Finance/Admin staff, and IT support. We do not sell personal information.',
      },
      {
        heading: 'How long we keep it',
        body: 'Information is retained while your account is active and for as long as required by MSU records retention, audit, and accountability rules.',
      },
      {
        heading: 'Your rights',
        body: 'Under the Data Privacy Act of 2012, you may request access to, correction of, or information about your personal data, and you may raise concerns with the MSU Data Protection Officer or the National Privacy Commission.',
      },
      {
        heading: 'Security',
        body: 'The system uses authenticated accounts, role-based access, and audit logging. Report suspected unauthorized access or incorrect records to the Program Leader or IT support immediately.',
      },
    ],
  },
};

export default function Policy({ type = 'terms' }) {
  const content = CONTENT[type] || CONTENT.terms;

  return (
    <AuthShell
      title={content.brand.title}
      lead={content.brand.lead}
      items={content.brand.items}
      maxWidth={580}
    >
      <div className="auth-form__heading">
        <h1 className="auth-form__title">{content.brand.title}</h1>
        <p className="auth-form__subtitle">Mindanao State University – Main Campus</p>
      </div>

      <div className="auth-policy">
        <p>{content.intro}</p>
        {content.sections.map((section) => (
          <section key={section.heading}>
            <h2>{section.heading}</h2>
            <p>{section.body}</p>
          </section>
        ))}
        <div className="auth-policy__back">
          <Link to="/register" className="auth-link">Back to registration</Link>
          <span aria-hidden="true">·</span>
          <Link to="/login" className="auth-link">Sign in</Link>
        </div>
      </div>
    </AuthShell>
  );
}
