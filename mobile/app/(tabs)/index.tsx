import { ScrollView, View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { StatCard } from '../../src/components/StatCard';
import { Card } from '../../src/components/Card';
import { ProgressRow } from '../../src/components/ProgressRow';
import { ActivityItem } from '../../src/components/ActivityItem';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useDashboard } from '../../src/hooks/useDashboard';
import { colors } from '../../src/constants/colors';

export default function Dashboard() {
  const { data, loading, error, reload } = useDashboard();

  if (loading && !data) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
        <TopBar />
        <LoadingState />
      </View>
    );
  }

  if (error && !data) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
        <TopBar />
        <ErrorState message={error.message} onRetry={reload} />
      </View>
    );
  }

  if (!data) return null;

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <TopBar />
      <View
        style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginHorizontal: 20,
          marginTop: 14,
        }}
      >
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Dashboard</Text>
        <Pressable
          onPress={() => router.push('/write')}
          style={{ backgroundColor: colors.blue600, paddingVertical: 8, paddingHorizontal: 14, borderRadius: 8 }}
        >
          <Text style={{ color: 'white', fontSize: 12, fontWeight: '600' }}>+ Write Today</Text>
        </Pressable>
      </View>

      {data.stats.missing_this_week > 0 ? (
        <Banner variant="warn">
          You have {data.stats.missing_this_week} missing {data.stats.missing_this_week === 1 ? 'entry' : 'entries'}{' '}
          this week. Journals compile every Monday at 12:00 AM.
        </Banner>
      ) : null}

      <View
        style={{
          flexDirection: 'row',
          flexWrap: 'wrap',
          justifyContent: 'space-between',
          gap: 10,
          marginHorizontal: 20,
          marginTop: 16,
        }}
      >
        <StatCard label="Total Entries" value={data.stats.entries_submitted_total} sub="Submitted" accent />
        <StatCard label="Weekly Reports Approved" value={data.stats.weekly_logs_approved} sub="By supervisor" />
        <StatCard label="Weekly Reports Pending" value={data.stats.weekly_logs_pending} sub="Awaiting review" />
        <StatCard label="Missing This Week" value={data.stats.missing_this_week} sub="Not yet submitted" danger />
      </View>

      <Card title="Completion Progress">
        <ProgressRow name="Weekly Reports Approved" pct={data.progress.weekly_reports_approved_percent} variant="dark" />
        <ProgressRow name="OJT Duration Progress" pct={data.progress.ojt_duration_percent} variant="light" />
      </Card>

      <Card title="Recent Activity">
        {data.recent_activity.length === 0 ? (
          <Text style={{ fontSize: 12, color: colors.gray400 }}>No recent activity yet.</Text>
        ) : (
          data.recent_activity.map((a, i) => <ActivityItem key={i} tone={a.tone} text={a.text} time={a.time} />)
        )}
      </Card>

      <Card title="Internship Details">
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          <DetailBlock label="Host Company" value={data.internship.host_company} />
          <DetailBlock label="Supervisor" value={data.internship.supervisor} />
          <DetailBlock label="Coordinator" value={data.internship.coordinator} />
          <DetailBlock label="Department" value={data.internship.department} />
          <DetailBlock label="Program" value={data.internship.program} />
          <DetailBlock label="Start Date" value={data.internship.start_date} />
        </View>
      </Card>
    </ScrollView>
  );
}

function DetailBlock({ label, value }: { label: string; value: string | null }) {
  return (
    <View style={{ width: '50%', marginTop: 12 }}>
      <Text style={{ fontSize: 10, color: colors.gray400, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 3 }}>
        {label}
      </Text>
      <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black }}>{value ?? '—'}</Text>
    </View>
  );
}
