package dl;

import java.io.File;
import java.io.FileNotFoundException;
import java.io.IOException;
import java.io.InputStream;
import java.io.RandomAccessFile;
import java.util.ArrayList;
import java.net.URL;
import java.net.URLConnection;

interface IDownloadWrk {
    public String NextDownloadURL(worker wrk);
}

class worker extends Thread {

    private String file;
    private byte[] buf;

    IDownloadWrk inf;

    public int id;
    public long fileOffset;
    public int bufOffset;
    public int bufOffsetSize;

    public long resourceOffset;
    public long resourceOffsetSize;

    public worker(IDownloadWrk inf, String file, byte[] buf) {
        this.inf = inf;
        this.file = file;
        this.buf = buf;
    }

    @Override
    public void run() {
        
        RandomAccessFile writer = null;
        try {
            writer = new RandomAccessFile(this.file, "rw");

            while (true) {

                String url = this.inf.NextDownloadURL(this);
                if (url == null) {
                    System.out.println(this.id+"# download complete");
                    break;
                }

                this.download(url, writer);
            }

        } catch (Exception e) {

            e.printStackTrace();

        } finally {
            try {
                if(writer != null) {
                    writer.close();
                }
            } catch (IOException e) {
                e.printStackTrace();
            }
        }

        super.run();
    }

    private boolean download(String urlstr, RandomAccessFile writer) throws Exception {

        URL url = new URL(urlstr);
        
        URLConnection conn = url.openConnection();
        conn.setConnectTimeout(5*1000);
        conn.setReadTimeout(10*1000);

        conn.setAllowUserInteraction(true);
        
        long resourceSize;
        if(this.resourceOffset >= 0 && this.resourceOffsetSize > 0) {
            resourceSize = this.resourceOffsetSize;
            long byteOffset = this.resourceOffset + this.resourceOffsetSize - 1;
            conn.setRequestProperty("Range", "bytes="+(this.resourceOffset)+"-"+byteOffset);
        } else {
            resourceSize = conn.getContentLengthLong();
            if(resourceSize <= 0) {
                return false;
            }
        }

        InputStream input = conn.getInputStream();
        
        writer.seek(this.fileOffset);

        while(resourceSize > 0)
        {
            int len = input.read(this.buf, this.bufOffset, this.bufOffsetSize);
            if(len == -1) {
                break;
            }

            resourceSize -= len;
            writer.write(this.buf, this.bufOffset, len);
        }

        input.close();
        return resourceSize == 0;
    }
}

public class Thunder implements IDownloadWrk {

    int threadNum;
    int bufSize;
    byte[] downloadBuf;

    ArrayList<String> urls;
    int urlIndex;

    long fileSize;

    public Thunder(int threadNum, int bufSize) {
        this.threadNum = threadNum;
        this.bufSize = bufSize;
    }

    public boolean Flash(ArrayList<String> downloadURLs, String file, long fileSize) {

        this.urlIndex = 0;
        this.fileSize = fileSize;
        this.urls = downloadURLs;
        this.downloadBuf = new byte[this.bufSize];

        int unitBufSize = this.bufSize/this.threadNum;

        for (int i = 0; i < this.threadNum; i++) {
            worker wrk = new worker(this, file, this.downloadBuf);
            wrk.bufOffset = i*unitBufSize;
            wrk.bufOffsetSize = unitBufSize;
            wrk.start();
        }

        return true;
    }

    public synchronized String NextDownloadURL(worker wrk) {

        if(this.urlIndex >= this.urls.size()) {
            return null;
        }

        wrk.id = this.urlIndex;
        long unitSize = this.fileSize/this.urls.size();

        String url = this.urls.get(this.urlIndex);
        wrk.fileOffset = this.urlIndex * unitSize;
        wrk.resourceOffset = wrk.fileOffset;
        wrk.resourceOffsetSize = unitSize;

        if(this.urlIndex+1 >= this.urls.size()) {
            wrk.resourceOffsetSize = this.fileSize - (this.urlIndex * unitSize);
        }

        this.urlIndex++;

        return url;
    }
}
