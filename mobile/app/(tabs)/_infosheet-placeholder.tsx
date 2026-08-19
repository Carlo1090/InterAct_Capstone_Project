// This route exists only because the "Info Sheet" tab in _layout.tsx needs a
// matching file on disk (Expo Router requires it) — its tabPress listener
// intercepts the press and pushes /infosheet instead, so this component
// never actually renders. Keep it a no-op.
import { View } from 'react-native';

export default function InfoSheetPlaceholder() {
  return <View />;
}
