import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  allowedDevOrigins: [
    "192.168.3.168",
    "192.168.3.168:3000",
    "localhost:3000",
    "127.0.0.1:3000"
  ],
  async rewrites() {
    return [
      {
        source: "/backend-api/:path*",
        destination: "http://192.168.3.168/jobrequest_phu/api/:path*",
      },
    ];
  },
};

export default nextConfig;
