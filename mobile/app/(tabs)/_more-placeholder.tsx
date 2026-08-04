// This route exists only because the "More" tab in _layout.tsx needs a
// matching file on disk (Expo Router requires it) — its tabPress listener
// intercepts the press and pushes /more instead, so this component never
// actually renders. Keep it a no-op.
import { View } from 'react-native';

export default function MorePlaceholder() {
  return <View />;
}
