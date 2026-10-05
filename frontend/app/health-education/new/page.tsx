'use client';

import React from 'react';
import { ActivityForm } from '@/components/health-education/ActivityForm';

export default function NewActivityPage() {
  return (
    <div className="mx-auto max-w-5xl">
      <ActivityForm isEdit={false} />
    </div>
  );
}
