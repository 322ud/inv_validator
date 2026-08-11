import { afterEach, beforeEach, vi } from 'vitest';

class ResizeObserverStub {
    observe() {}
    disconnect() {}
}

beforeEach(() => {
    vi.stubGlobal('ResizeObserver', ResizeObserverStub);
    vi.stubGlobal('requestAnimationFrame', vi.fn(() => 1));
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
    vi.stubGlobal('OC', {
        requestToken: 'test-request-token',
        generateUrl: vi.fn((url) => `/nextcloud${url}`),
    });
});

afterEach(() => {
    document.body.replaceChildren();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});
