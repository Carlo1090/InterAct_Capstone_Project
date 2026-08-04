import { ScrollView, View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { GuideCard } from '../src/components/GuideCard';
import { colors } from '../src/constants/colors';

export default function Guide() {
  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <View style={{ paddingTop: 50, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Activity Log Guide</Text>
      </View>

      <GuideCard
        icon="checkmark-circle-outline"
        title="Submission Requirements"
        sub="What every entry must include"
        items={[
          'Every entry needs a Daily Accomplishment section',
          'Up to 1,500 characters total per entry (no minimum)',
          'Describe specific tasks completed during the day',
          'The optional SIPP Report section (Issues, Solutions, Recommendations) caps at 300 characters per field',
          'A day stays editable until its week is compiled',
        ]}
      />

      <GuideCard
        icon="calendar-outline"
        title="Weekly Compilation Schedule"
        sub="How entries become weekly reports"
        items={[
          'Daily entries are compiled every Monday at 12:00 AM, for the week that just ended',
          'Once a week is compiled, its daily entries can no longer be edited',
          'Weekly journals are forwarded to your OJT coordinator',
          'Supervisor reviews and approves or returns each weekly journal',
          'Returned journals must be revised and resubmitted',
        ]}
      />

      <GuideCard
        icon="information-circle-outline"
        title="Daily Entry Statuses"
        sub="Your day-by-day journal entries"
        items={[
          'Draft — Saved, not yet submitted, still editable',
          'Submitted — Sent in, still editable until its week is compiled',
        ]}
      />

      <GuideCard
        icon="information-circle-outline"
        title="Weekly Journal Statuses"
        sub="The compiled narrative for each week"
        items={[
          'Draft — Compiled, not yet submitted to your supervisor',
          'Submitted — Awaiting Review — sent in, waiting on your supervisor',
          'Approved — Supervisor has reviewed and accepted',
          'Returned for Revision — Needs changes before you resubmit',
        ]}
      />
    </ScrollView>
  );
}
