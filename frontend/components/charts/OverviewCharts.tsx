'use client';

import React from 'react';
import {
  ResponsiveContainer,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  CartesianGrid,
  PieChart,
  Pie,
  Cell,
  LineChart,
  Line,
  Legend,
} from 'recharts';

const COLORS = ['#2563EB', '#16A34A', '#D97706', '#9333EA', '#0284C7', '#DC2626', '#475569'];

interface MonthlyTrendProps {
  data: Array<{ month: string; activities: number; participants: number }>;
}

export function MonthlyActivitiesChart({ data }: MonthlyTrendProps) {
  if (!data || data.length === 0) {
    return <div className="flex h-64 items-center justify-center text-sm text-slate-400">No activity data recorded yet.</div>;
  }

  return (
    <ResponsiveContainer width="100%" height={280}>
      <BarChart data={data} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
        <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#64748B' }} />
        <YAxis tick={{ fontSize: 11, fill: '#64748B' }} />
        <Tooltip
          contentStyle={{ backgroundColor: '#0F172A', borderRadius: '8px', color: '#fff', border: 'none', fontSize: '12px' }}
        />
        <Bar dataKey="activities" name="Activities" fill="#2563EB" radius={[4, 4, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export function ParticipantsTrendChart({ data }: MonthlyTrendProps) {
  if (!data || data.length === 0) {
    return <div className="flex h-64 items-center justify-center text-sm text-slate-400">No participant data recorded yet.</div>;
  }

  return (
    <ResponsiveContainer width="100%" height={280}>
      <LineChart data={data} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
        <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#64748B' }} />
        <YAxis tick={{ fontSize: 11, fill: '#64748B' }} />
        <Tooltip
          contentStyle={{ backgroundColor: '#0F172A', borderRadius: '8px', color: '#fff', border: 'none', fontSize: '12px' }}
        />
        <Line type="monotone" dataKey="participants" name="Participants" stroke="#16A34A" strokeWidth={3} dot={{ r: 4 }} />
      </LineChart>
    </ResponsiveContainer>
  );
}

interface PieChartProps {
  data: Array<{ name: string; value: number }>;
}

export function ActivityTypesPieChart({ data }: PieChartProps) {
  if (!data || data.length === 0) {
    return <div className="flex h-64 items-center justify-center text-sm text-slate-400">No activity types available.</div>;
  }

  return (
    <ResponsiveContainer width="100%" height={280}>
      <PieChart>
        <Pie
          data={data}
          cx="50%"
          cy="50%"
          innerRadius={55}
          outerRadius={85}
          paddingAngle={4}
          dataKey="value"
        >
          {data.map((_, index) => (
            <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
          ))}
        </Pie>
        <Tooltip
          contentStyle={{ backgroundColor: '#0F172A', borderRadius: '8px', color: '#fff', border: 'none', fontSize: '12px' }}
        />
        <Legend
          formatter={(value) => <span className="text-xs text-slate-600 font-medium">{value}</span>}
          layout="horizontal"
          verticalAlign="bottom"
          align="center"
        />
      </PieChart>
    </ResponsiveContainer>
  );
}

interface BarListProps {
  data: Array<{ ward?: string; topic?: string; method?: string; count: number; participants?: number }>;
  nameKey: 'ward' | 'topic' | 'method';
}

export function HorizontalBarChart({ data, nameKey }: BarListProps) {
  if (!data || data.length === 0) {
    return <div className="flex h-64 items-center justify-center text-sm text-slate-400">No data available.</div>;
  }

  const chartData = data.map((item) => ({
    label: item[nameKey] || 'Unassigned',
    count: item.count,
  }));

  return (
    <ResponsiveContainer width="100%" height={280}>
      <BarChart data={chartData} layout="vertical" margin={{ top: 5, right: 20, left: 30, bottom: 5 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" horizontal={false} />
        <XAxis type="number" tick={{ fontSize: 11, fill: '#64748B' }} />
        <YAxis dataKey="label" type="category" tick={{ fontSize: 11, fill: '#334155' }} width={90} />
        <Tooltip
          contentStyle={{ backgroundColor: '#0F172A', borderRadius: '8px', color: '#fff', border: 'none', fontSize: '12px' }}
        />
        <Bar dataKey="count" fill="#2563EB" radius={[0, 4, 4, 0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}
