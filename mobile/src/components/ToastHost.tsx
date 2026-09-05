import { useEffect, useRef, useSyncExternalStore } from 'react';
import { Animated, Pressable, Text, View, StyleSheet, AccessibilityInfo } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';
import {
  dismissToast,
  getToastSnapshot,
  subscribeToToast,
  ToastKind,
} from '../services/toast';

/**
 * Renders the one toast. Mounted once at the app root, above the navigator,
 * so it is visible from every screen including modals.
 *
 * Solid fills rather than the tinted washes the inline Banner uses: a Banner
 * sits inside a page and should not shout, but this appears over content and
 * has one job — to be noticed.
 */
const STYLE: Record<ToastKind, { bg: string; icon: keyof typeof Ionicons.glyphMap }> = {
  error: { bg: colors.redDark, icon: 'alert-circle' },
  success: { bg: colors.greenTx, icon: 'checkmark-circle' },
  info: { bg: colors.blue600, icon: 'information-circle' },
};

export function ToastHost() {
  const toast = useSyncExternalStore(subscribeToToast, getToastSnapshot, getToastSnapshot);
  const slide = useRef(new Animated.Value(-140)).current;

  useEffect(() => {
    if (toast) {
      // Screen readers do not notice a purely visual change, so the message is
      // announced as well as shown.
      AccessibilityInfo.announceForAccessibility?.(toast.message);
    }

    Animated.spring(slide, {
      toValue: toast ? 0 : -140,
      useNativeDriver: true,
      speed: 18,
      bounciness: 4,
    }).start();
  }, [toast, slide]);

  if (!toast) return null;

  const s = STYLE[toast.kind];

  return (
    <Animated.View
      pointerEvents="box-none"
      style={{
        position: 'absolute',
        top: 0,
        left: 0,
        right: 0,
        transform: [{ translateY: slide }],
        zIndex: 1000,
        elevation: 1000,
      }}
    >
      <Pressable
        onPress={dismissToast}
        accessibilityRole="alert"
        accessibilityLabel={`${toast.message}${toast.detail ? `. ${toast.detail}` : ''}`}
        style={{
          backgroundColor: s.bg,
          paddingTop: 48,
          paddingBottom: 14,
          paddingHorizontal: 18,
          flexDirection: 'row',
          alignItems: 'flex-start',
          gap: 11,
          ...StyleSheet.flatten({
            shadowColor: '#000',
            shadowOpacity: 0.25,
            shadowRadius: 12,
            shadowOffset: { width: 0, height: 4 },
          }),
        }}
      >
        <Ionicons name={s.icon} size={20} color="white" style={{ marginTop: 1 }} />
        <View style={{ flex: 1 }}>
          <Text style={{ color: 'white', fontSize: 13.5, fontWeight: '700', lineHeight: 19 }}>
            {toast.message}
          </Text>
          {toast.detail ? (
            <Text style={{ color: 'rgba(255,255,255,0.92)', fontSize: 12, lineHeight: 17, marginTop: 3 }}>
              {toast.detail}
            </Text>
          ) : null}
        </View>
        <Ionicons name="close" size={17} color="rgba(255,255,255,0.85)" style={{ marginTop: 2 }} />
      </Pressable>
    </Animated.View>
  );
}
