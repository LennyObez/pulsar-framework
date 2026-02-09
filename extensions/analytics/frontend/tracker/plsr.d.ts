type PlsrPayload = {
  type: "pageview" | "event";
  site: string;
  url: string;
  referrer?: string;
  screen_width?: number;
  event_name?: string;
  event_props?: Record<string, string | number | boolean>;
  revenue_value?: number;
};

type PlsrSendFn = (payload: PlsrPayload) => void;

interface PlsrApi {
  event: (
    name: string,
    props?: Record<string, string | number | boolean>,
    revenue?: number,
  ) => void;
  ext: (fn: (send: PlsrSendFn, site: string) => void) => void;
}

declare var plsr: PlsrApi;
